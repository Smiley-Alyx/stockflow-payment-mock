<?php

namespace App\Domain\Payment\Services\Capture;

use App\Domain\Payment\DTO\CaptureRequest;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentAttemptType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentStateException;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\IdempotencyRecord;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Services\Debug\PaymentOperation;
use App\Domain\Payment\Services\Debug\ProviderDegradationSimulator;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Domain\Payment\Services\PaymentLedger;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use App\Domain\Payment\Services\Sandbox\SandboxCardBehaviorEvaluator;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentCaptureService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly SandboxPaymentMethodResolver $paymentMethodResolver,
        private readonly SandboxCardBehaviorEvaluator $behaviorEvaluator,
        private readonly SandboxBalanceService $balanceService,
        private readonly PaymentIdempotencyService $idempotencyService,
        private readonly ProviderDegradationSimulator $degradationSimulator,
    ) {}

    public function capture(CaptureRequest $request): CaptureResult
    {
        $existingRecord = $this->idempotencyService->findCaptureRecord(
            $request->paymentId,
            $request->idempotencyKey,
        );

        if ($existingRecord !== null) {
            return $this->idempotencyService->replayCapture($existingRecord);
        }

        try {
            return DB::transaction(function () use ($request): CaptureResult {
                $payment = Payment::query()
                    ->where('payment_id', $request->paymentId)
                    ->lockForUpdate()
                    ->first();

                if ($payment === null) {
                    throw new InvalidPaymentStateException(
                        $request->paymentId,
                        PaymentStatus::Created,
                        'capture',
                    );
                }

                if (! $this->canCapture($payment)) {
                    throw new InvalidPaymentStateException(
                        $payment->payment_id,
                        $payment->status,
                        'capture',
                    );
                }

                $authorization = $this->ledger->approvedAuthorization($payment);

                if ($authorization === null) {
                    throw new InvalidPaymentStateException(
                        $payment->payment_id,
                        $payment->status,
                        'capture',
                    );
                }

                if ($this->ledger->hasCompletedCapture($payment)) {
                    throw new InvalidPaymentStateException(
                        $payment->payment_id,
                        $payment->status,
                        'capture',
                    );
                }

                $captureAmount = $request->amountValue ?? $authorization->amount_value;
                $captureCurrency = $request->amountCurrency ?? $authorization->amount_currency;

                if ($captureAmount > $authorization->amount_value) {
                    throw new \InvalidArgumentException('Capture amount exceeds authorized amount.');
                }

                $payment->transitionTo(PaymentStatus::CapturePending);
                $payment->save();

                $attempt = PaymentAttempt::query()->create([
                    'payment_id' => $payment->id,
                    'type' => PaymentAttemptType::Capture,
                    'status' => PaymentAttemptStatus::Pending,
                    'idempotency_key' => $request->idempotencyKey,
                    'message_id' => $request->messageId,
                    'correlation_id' => $request->correlationId,
                    'metadata' => $request->metadata,
                ]);

                $result = $this->processCapture(
                    $payment,
                    $attempt,
                    $authorization,
                    $captureAmount,
                    $captureCurrency,
                );

                $this->idempotencyService->storeCaptureRecord(
                    $payment,
                    $attempt,
                    $request->idempotencyKey,
                    $this->idempotencyService->captureFingerprint($result),
                );

                return $result;
            });
        } catch (QueryException $exception) {
            if ($this->isIdempotencyConflict($exception)) {
                $record = $this->idempotencyService->findCaptureRecord(
                    $request->paymentId,
                    $request->idempotencyKey,
                );

                if ($record !== null) {
                    return $this->idempotencyService->replayCapture($record);
                }
            }

            throw $exception;
        }
    }

    private function processCapture(
        Payment $payment,
        PaymentAttempt $attempt,
        Authorization $authorization,
        int $captureAmount,
        string $captureCurrency,
    ): CaptureResult {
        $this->degradationSimulator->beforeProcessing(PaymentOperation::Capture);

        $simulatedFailure = $this->degradationSimulator->captureDeclineReason();

        if ($simulatedFailure !== null) {
            return $this->failCapture(
                $payment,
                $attempt,
                $authorization,
                $captureAmount,
                $captureCurrency,
                $simulatedFailure,
            );
        }

        $card = $this->paymentMethodResolver->resolve($payment->payment_method_token);

        $failureReason = $this->behaviorEvaluator->captureDeclineReason($card);

        if ($failureReason !== null) {
            return $this->failCapture(
                $payment,
                $attempt,
                $authorization,
                $captureAmount,
                $captureCurrency,
                $failureReason,
            );
        }

        return $this->completeCapture(
            $payment,
            $attempt,
            $authorization,
            $captureAmount,
            $captureCurrency,
        );
    }

    private function completeCapture(
        Payment $payment,
        PaymentAttempt $attempt,
        Authorization $authorization,
        int $captureAmount,
        string $captureCurrency,
    ): CaptureResult {
        $captureId = Capture::generatePrefixedId('cap');
        $capturedAt = now();

        $capture = Capture::query()->create([
            'capture_id' => $captureId,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'authorization_id' => $authorization->id,
            'amount_value' => $captureAmount,
            'amount_currency' => $captureCurrency,
            'status' => CaptureStatus::Completed,
            'captured_at' => $capturedAt,
        ]);

        $attempt->fill([
            'status' => PaymentAttemptStatus::Completed,
            'completed_at' => $capturedAt,
        ])->save();

        $payment->transitionTo(PaymentStatus::Captured);
        $payment->captured_at = $capturedAt;
        $payment->save();

        return new CaptureResult(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            capture: $capture,
            completed: true,
            idempotentReplay: false,
        );
    }

    private function failCapture(
        Payment $payment,
        PaymentAttempt $attempt,
        Authorization $authorization,
        int $captureAmount,
        string $captureCurrency,
        DeclineReasonCode $reason,
    ): CaptureResult {
        $captureId = Capture::generatePrefixedId('cap');
        $completedAt = now();

        $capture = Capture::query()->create([
            'capture_id' => $captureId,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'authorization_id' => $authorization->id,
            'amount_value' => $captureAmount,
            'amount_currency' => $captureCurrency,
            'status' => CaptureStatus::Failed,
            'reason_code' => $reason->value,
            'reason_message' => $reason->message(),
        ]);

        $attempt->fill([
            'status' => PaymentAttemptStatus::Failed,
            'reason_code' => $reason->value,
            'reason_message' => $reason->message(),
            'completed_at' => $completedAt,
        ])->save();

        $this->balanceService->release($payment->payment_method_token, $authorization->amount_value);

        $payment->transitionTo(PaymentStatus::CaptureFailed);
        $payment->save();

        return new CaptureResult(
            payment: $payment->fresh(),
            attempt: $attempt->fresh(),
            capture: $capture,
            completed: false,
            idempotentReplay: false,
            failureReason: $reason,
        );
    }

    private function canCapture(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Authorized;
    }

    private function isIdempotencyConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'idempotency_records')
            || str_contains($message, 'payment_attempts');
    }
}
