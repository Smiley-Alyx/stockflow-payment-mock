<?php

namespace App\Domain\Payment\Services\Refund;

use App\Domain\Payment\DTO\RefundRequest;
use App\Domain\Payment\DTO\RefundResult;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentAttemptType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentStateException;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Domain\Payment\Services\PaymentLedger;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use App\Domain\Payment\Services\Sandbox\SandboxCardBehaviorEvaluator;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentRefundService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly SandboxPaymentMethodResolver $paymentMethodResolver,
        private readonly SandboxCardBehaviorEvaluator $behaviorEvaluator,
        private readonly SandboxBalanceService $balanceService,
        private readonly PaymentIdempotencyService $idempotencyService,
    ) {}

    public function refund(RefundRequest $request): RefundResult
    {
        $existingRecord = $this->idempotencyService->findRefundRecord(
            $request->paymentId,
            $request->idempotencyKey,
        );

        if ($existingRecord !== null) {
            return $this->idempotencyService->replayRefund($existingRecord);
        }

        try {
            return DB::transaction(function () use ($request): RefundResult {
                $payment = Payment::query()
                    ->where('payment_id', $request->paymentId)
                    ->lockForUpdate()
                    ->first();

                if ($payment === null) {
                    throw new InvalidPaymentStateException(
                        $request->paymentId,
                        PaymentStatus::Created,
                        'refund',
                    );
                }

                if (! $this->canRefund($payment)) {
                    if ($this->isNotCaptured($payment)) {
                        return $this->failValidation(
                            $payment,
                            $request,
                            DeclineReasonCode::RefundFailed,
                            'Payment has not been captured',
                        );
                    }

                    throw new InvalidPaymentStateException(
                        $payment->payment_id,
                        $payment->status,
                        'refund',
                    );
                }

                $capture = $this->latestCompletedCapture($payment);

                if ($capture === null) {
                    return $this->failValidation(
                        $payment,
                        $request,
                        DeclineReasonCode::RefundFailed,
                        'Payment has not been captured',
                    );
                }

                $refundableAmount = $this->ledger->refundableAmount($payment);

                if ($refundableAmount <= 0) {
                    return $this->failValidation(
                        $payment,
                        $request,
                        DeclineReasonCode::RefundFailed,
                        'Payment has not been captured',
                    );
                }

                $refundAmount = $request->amountValue ?? $refundableAmount;
                $refundCurrency = $request->amountCurrency ?? $capture->amount_currency;

                if ($refundAmount > $refundableAmount) {
                    return $this->failValidation(
                        $payment,
                        $request,
                        DeclineReasonCode::RefundFailed,
                        'Refund amount exceeds captured amount',
                    );
                }

                $payment->transitionTo(PaymentStatus::RefundPending);
                $payment->save();

                $attempt = PaymentAttempt::query()->create([
                    'payment_id' => $payment->id,
                    'type' => PaymentAttemptType::Refund,
                    'status' => PaymentAttemptStatus::Pending,
                    'idempotency_key' => $request->idempotencyKey,
                    'message_id' => $request->messageId,
                    'correlation_id' => $request->correlationId,
                    'metadata' => $request->metadata,
                ]);

                $result = $this->processRefund(
                    $payment,
                    $attempt,
                    $capture,
                    $refundAmount,
                    $refundCurrency,
                );

                $this->idempotencyService->storeRefundRecord(
                    $payment,
                    $attempt,
                    $request->idempotencyKey,
                    $this->idempotencyService->refundFingerprint($result),
                );

                return $result;
            });
        } catch (QueryException $exception) {
            if ($this->isIdempotencyConflict($exception)) {
                $record = $this->idempotencyService->findRefundRecord(
                    $request->paymentId,
                    $request->idempotencyKey,
                );

                if ($record !== null) {
                    return $this->idempotencyService->replayRefund($record);
                }
            }

            throw $exception;
        }
    }

    private function processRefund(
        Payment $payment,
        PaymentAttempt $attempt,
        Capture $capture,
        int $refundAmount,
        string $refundCurrency,
    ): RefundResult {
        $card = $this->paymentMethodResolver->resolve($payment->payment_method_token);

        $failureReason = $this->behaviorEvaluator->refundDeclineReason($card);

        if ($failureReason !== null) {
            return $this->failRefund(
                $payment,
                $attempt,
                $capture,
                $refundAmount,
                $refundCurrency,
                $failureReason,
                $failureReason->message(),
            );
        }

        return $this->completeRefund(
            $payment,
            $attempt,
            $capture,
            $refundAmount,
            $refundCurrency,
        );
    }

    private function completeRefund(
        Payment $payment,
        PaymentAttempt $attempt,
        Capture $capture,
        int $refundAmount,
        string $refundCurrency,
    ): RefundResult {
        $refundId = Refund::generatePrefixedId('ref');
        $refundedAt = now();

        $refund = Refund::query()->create([
            'refund_id' => $refundId,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'capture_id' => $capture->id,
            'amount_value' => $refundAmount,
            'amount_currency' => $refundCurrency,
            'status' => RefundStatus::Completed,
            'refunded_at' => $refundedAt,
        ]);

        $attempt->fill([
            'status' => PaymentAttemptStatus::Completed,
            'completed_at' => $refundedAt,
        ])->save();

        $this->balanceService->release($payment->payment_method_token, $refundAmount);

        $remainingRefundable = $this->ledger->refundableAmount($payment->fresh());

        if ($remainingRefundable === 0) {
            $payment->transitionTo(PaymentStatus::Refunded);
            $payment->refunded_at = $refundedAt;
        } else {
            $payment->transitionTo(PaymentStatus::PartiallyRefunded);
        }

        $payment->save();

        return new RefundResult(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            refund: $refund,
            completed: true,
            idempotentReplay: false,
        );
    }

    private function failRefund(
        Payment $payment,
        PaymentAttempt $attempt,
        Capture $capture,
        int $refundAmount,
        string $refundCurrency,
        DeclineReasonCode $reason,
        string $message,
    ): RefundResult {
        $refundId = Refund::generatePrefixedId('ref');
        $completedAt = now();

        $refund = Refund::query()->create([
            'refund_id' => $refundId,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'capture_id' => $capture->id,
            'amount_value' => $refundAmount,
            'amount_currency' => $refundCurrency,
            'status' => RefundStatus::Failed,
            'reason_code' => $reason->value,
            'reason_message' => $message,
        ]);

        $attempt->fill([
            'status' => PaymentAttemptStatus::Failed,
            'reason_code' => $reason->value,
            'reason_message' => $message,
            'completed_at' => $completedAt,
        ])->save();

        $payment->transitionTo(PaymentStatus::RefundFailed);
        $payment->save();

        return new RefundResult(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            refund: $refund,
            completed: false,
            idempotentReplay: false,
            failureReason: $reason,
        );
    }

    private function failValidation(
        Payment $payment,
        RefundRequest $request,
        DeclineReasonCode $reason,
        string $message,
    ): RefundResult {
        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'type' => PaymentAttemptType::Refund,
            'status' => PaymentAttemptStatus::Failed,
            'idempotency_key' => $request->idempotencyKey,
            'message_id' => $request->messageId,
            'correlation_id' => $request->correlationId,
            'metadata' => $request->metadata,
            'reason_code' => $reason->value,
            'reason_message' => $message,
            'completed_at' => now(),
        ]);

        $result = new RefundResult(
            payment: $payment->fresh(),
            attempt: $attempt,
            refund: null,
            completed: false,
            idempotentReplay: false,
            failureReason: $reason,
        );

        $this->idempotencyService->storeRefundRecord(
            $payment,
            $attempt,
            $request->idempotencyKey,
            $this->idempotencyService->refundFingerprint($result),
        );

        return $result;
    }

    private function latestCompletedCapture(Payment $payment): ?Capture
    {
        return $payment->captures()
            ->where('status', CaptureStatus::Completed)
            ->latest('created_at')
            ->first();
    }

    private function canRefund(Payment $payment): bool
    {
        return in_array($payment->status, [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyRefunded,
        ], true);
    }

    private function isNotCaptured(Payment $payment): bool
    {
        return in_array($payment->status, [
            PaymentStatus::Authorized,
            PaymentStatus::CapturePending,
            PaymentStatus::CaptureFailed,
            PaymentStatus::AuthorizationDeclined,
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
