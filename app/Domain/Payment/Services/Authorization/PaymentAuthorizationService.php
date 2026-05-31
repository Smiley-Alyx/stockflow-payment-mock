<?php

namespace App\Domain\Payment\Services\Authorization;

use App\Domain\Payment\DTO\AuthorizationRequest;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentAttemptType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentStateException;
use App\Domain\Payment\Exceptions\InvalidSandboxCardTokenException;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use App\Domain\Payment\Services\Sandbox\SandboxCardBehaviorEvaluator;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentAuthorizationService
{
    public function __construct(
        private readonly SandboxPaymentMethodResolver $paymentMethodResolver,
        private readonly SandboxCardBehaviorEvaluator $behaviorEvaluator,
        private readonly SandboxBalanceService $balanceService,
        private readonly PaymentIdempotencyService $idempotencyService,
    ) {}

    public function authorize(AuthorizationRequest $request): AuthorizationResult
    {
        $existingRecord = $this->idempotencyService->findAuthorizationRecord(
            $request->paymentId,
            $request->idempotencyKey,
        );

        if ($existingRecord !== null) {
            return $this->idempotencyService->replayAuthorization($existingRecord);
        }

        try {
            return DB::transaction(function () use ($request): AuthorizationResult {
                $payment = Payment::query()
                    ->where('payment_id', $request->paymentId)
                    ->lockForUpdate()
                    ->first();

                if ($payment === null) {
                    $payment = $this->createPayment($request);
                } elseif (! $this->canAuthorize($payment)) {
                    throw new InvalidPaymentStateException(
                        $payment->payment_id,
                        $payment->status,
                        'authorization',
                    );
                }

                $payment->transitionTo(PaymentStatus::AuthorizationPending);
                $payment->save();

                $attempt = PaymentAttempt::query()->create([
                    'payment_id' => $payment->id,
                    'type' => PaymentAttemptType::Authorization,
                    'status' => PaymentAttemptStatus::Pending,
                    'idempotency_key' => $request->idempotencyKey,
                    'message_id' => $request->messageId,
                    'correlation_id' => $request->correlationId,
                    'metadata' => $request->metadata,
                ]);

                $result = $this->processAuthorization($payment, $attempt, $request);

                $this->idempotencyService->storeAuthorizationRecord(
                    $payment,
                    $attempt,
                    $request->idempotencyKey,
                    $this->idempotencyService->authorizationFingerprint($result),
                );

                return $result;
            });
        } catch (QueryException $exception) {
            if ($this->isIdempotencyConflict($exception)) {
                $record = $this->idempotencyService->findAuthorizationRecord(
                    $request->paymentId,
                    $request->idempotencyKey,
                );

                if ($record !== null) {
                    return $this->idempotencyService->replayAuthorization($record);
                }
            }

            throw $exception;
        }
    }

    private function processAuthorization(
        Payment $payment,
        PaymentAttempt $attempt,
        AuthorizationRequest $request,
    ): AuthorizationResult {
        try {
            $card = $this->paymentMethodResolver->resolve($request->paymentMethodToken);
        } catch (InvalidSandboxCardTokenException) {
            return $this->decline(
                $payment,
                $attempt,
                $request,
                DeclineReasonCode::InvalidCardToken,
            );
        }

        $declineReason = $this->behaviorEvaluator->authorizationDeclineReason(
            $card,
            $request->amountValue,
            $request->randomRoll,
        );

        if ($declineReason !== null) {
            return $this->decline($payment, $attempt, $request, $declineReason);
        }

        if (! $this->balanceService->reserve($request->paymentMethodToken, $request->amountValue)) {
            return $this->decline($payment, $attempt, $request, DeclineReasonCode::InsufficientFunds);
        }

        return $this->approve($payment, $attempt, $request);
    }

    private function approve(
        Payment $payment,
        PaymentAttempt $attempt,
        AuthorizationRequest $request,
    ): AuthorizationResult {
        $authorizationId = Authorization::generatePrefixedId('auth');
        $authorizedAt = now();

        $authorization = Authorization::query()->create([
            'authorization_id' => $authorizationId,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'amount_value' => $request->amountValue,
            'amount_currency' => $request->amountCurrency,
            'status' => AuthorizationStatus::Approved,
            'authorized_at' => $authorizedAt,
        ]);

        $attempt->fill([
            'status' => PaymentAttemptStatus::Completed,
            'completed_at' => $authorizedAt,
        ])->save();

        $payment->transitionTo(PaymentStatus::Authorized);
        $payment->authorized_at = $authorizedAt;
        $payment->save();

        return new AuthorizationResult(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            authorization: $authorization,
            approved: true,
            idempotentReplay: false,
        );
    }

    private function decline(
        Payment $payment,
        PaymentAttempt $attempt,
        AuthorizationRequest $request,
        DeclineReasonCode $reason,
    ): AuthorizationResult {
        $authorizationId = Authorization::generatePrefixedId('auth');
        $completedAt = now();

        $authorization = Authorization::query()->create([
            'authorization_id' => $authorizationId,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'amount_value' => $request->amountValue,
            'amount_currency' => $request->amountCurrency,
            'status' => AuthorizationStatus::Declined,
            'reason_code' => $reason->value,
            'reason_message' => $reason->message(),
        ]);

        $attempt->fill([
            'status' => PaymentAttemptStatus::Failed,
            'reason_code' => $reason->value,
            'reason_message' => $reason->message(),
            'completed_at' => $completedAt,
        ])->save();

        $payment->transitionTo(PaymentStatus::AuthorizationDeclined);
        $payment->save();

        return new AuthorizationResult(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            authorization: $authorization,
            approved: false,
            idempotentReplay: false,
            declineReason: $reason,
        );
    }

    private function createPayment(AuthorizationRequest $request): Payment
    {
        return Payment::query()->create([
            'payment_id' => $request->paymentId,
            'order_id' => $request->orderId,
            'customer_id' => $request->customerId,
            'amount_value' => $request->amountValue,
            'amount_currency' => $request->amountCurrency,
            'status' => PaymentStatus::Created,
            'capture_mode' => $request->captureMode,
            'payment_method_type' => $request->paymentMethodType,
            'payment_method_token' => $request->paymentMethodToken,
            'metadata' => $request->metadata,
        ]);
    }

    private function canAuthorize(Payment $payment): bool
    {
        return in_array($payment->status, [
            PaymentStatus::Created,
            PaymentStatus::AuthorizationPending,
        ], true);
    }

    private function isIdempotencyConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'idempotency_records')
            || str_contains($message, 'payment_attempts');
    }
}
