<?php

namespace App\Domain\Payment\Services\Idempotency;

use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\IdempotencyRecord;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;

class PaymentIdempotencyService
{
    public function findAuthorizationRecord(string $paymentId, string $idempotencyKey): ?IdempotencyRecord
    {
        return $this->findRecord(IdempotencyRecord::OPERATION_AUTHORIZATION, $paymentId, $idempotencyKey);
    }

    public function storeAuthorizationRecord(
        Payment $payment,
        PaymentAttempt $attempt,
        string $idempotencyKey,
        string $fingerprint,
    ): IdempotencyRecord {
        return $this->storeRecord(
            IdempotencyRecord::OPERATION_AUTHORIZATION,
            $payment,
            $attempt,
            $idempotencyKey,
            $fingerprint,
        );
    }

    public function authorizationFingerprint(AuthorizationResult $result): string
    {
        $authorization = $result->authorization;

        return hash('sha256', implode('|', [
            $result->payment->id,
            $result->attempt->id,
            $result->approved ? 'approved' : 'declined',
            $authorization?->id ?? '',
            $authorization?->status->value ?? '',
            $result->declineReason?->value ?? '',
        ]));
    }

    public function replayAuthorization(IdempotencyRecord $record): AuthorizationResult
    {
        $attempt = PaymentAttempt::query()
            ->with(['payment', 'authorization'])
            ->findOrFail($record->payment_attempt_id);

        $payment = $attempt->payment;
        $authorization = $attempt->authorization;
        $approved = $authorization?->status === AuthorizationStatus::Approved;

        $declineReason = null;

        if (! $approved && $attempt->reason_code !== null) {
            $declineReason = DeclineReasonCode::tryFrom($attempt->reason_code);
        }

        return new AuthorizationResult(
            payment: $payment,
            attempt: $attempt,
            authorization: $authorization,
            approved: $approved,
            idempotentReplay: true,
            declineReason: $declineReason,
        );
    }

    public function findCaptureRecord(string $paymentId, string $idempotencyKey): ?IdempotencyRecord
    {
        return $this->findRecord(IdempotencyRecord::OPERATION_CAPTURE, $paymentId, $idempotencyKey);
    }

    public function storeCaptureRecord(
        Payment $payment,
        PaymentAttempt $attempt,
        string $idempotencyKey,
        string $fingerprint,
    ): IdempotencyRecord {
        return $this->storeRecord(
            IdempotencyRecord::OPERATION_CAPTURE,
            $payment,
            $attempt,
            $idempotencyKey,
            $fingerprint,
        );
    }

    public function captureFingerprint(CaptureResult $result): string
    {
        return hash('sha256', implode('|', [
            $result->payment->id,
            $result->attempt->id,
            $result->completed ? 'completed' : 'failed',
            $result->capture?->id ?? '',
            $result->capture?->status->value ?? '',
            $result->failureReason?->value ?? '',
        ]));
    }

    public function replayCapture(IdempotencyRecord $record): CaptureResult
    {
        $attempt = PaymentAttempt::query()
            ->with(['payment', 'capture'])
            ->findOrFail($record->payment_attempt_id);

        $capture = $attempt->capture;
        $completed = $capture?->status === CaptureStatus::Completed;
        $failureReason = $attempt->reason_code !== null
            ? DeclineReasonCode::tryFrom($attempt->reason_code)
            : null;

        return new CaptureResult(
            payment: $attempt->payment,
            attempt: $attempt,
            capture: $capture,
            completed: $completed,
            idempotentReplay: true,
            failureReason: $completed ? null : $failureReason,
        );
    }

    public function findRefundRecord(string $paymentId, string $idempotencyKey): ?IdempotencyRecord
    {
        return $this->findRecord(IdempotencyRecord::OPERATION_REFUND, $paymentId, $idempotencyKey);
    }

    public function storeRefundRecord(
        Payment $payment,
        PaymentAttempt $attempt,
        string $idempotencyKey,
        string $fingerprint,
    ): IdempotencyRecord {
        return $this->storeRecord(
            IdempotencyRecord::OPERATION_REFUND,
            $payment,
            $attempt,
            $idempotencyKey,
            $fingerprint,
        );
    }

    public function refundFingerprint(RefundResult $result): string
    {
        return hash('sha256', implode('|', [
            $result->payment->id,
            $result->attempt->id,
            $result->completed ? 'completed' : 'failed',
            $result->refund?->id ?? '',
            $result->refund?->status->value ?? '',
            $result->failureReason?->value ?? '',
        ]));
    }

    public function replayRefund(IdempotencyRecord $record): RefundResult
    {
        $attempt = PaymentAttempt::query()
            ->with(['payment', 'refund'])
            ->findOrFail($record->payment_attempt_id);

        $refund = $attempt->refund;
        $completed = $refund?->status === RefundStatus::Completed;
        $failureReason = $attempt->reason_code !== null
            ? DeclineReasonCode::tryFrom($attempt->reason_code)
            : null;

        return new RefundResult(
            payment: $attempt->payment,
            attempt: $attempt,
            refund: $refund,
            completed: $completed,
            idempotentReplay: true,
            failureReason: $completed ? null : $failureReason,
        );
    }

    private function findRecord(string $operation, string $paymentId, string $idempotencyKey): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('operation', $operation)
            ->where('payment_id', $paymentId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function storeRecord(
        string $operation,
        Payment $payment,
        PaymentAttempt $attempt,
        string $idempotencyKey,
        string $fingerprint,
    ): IdempotencyRecord {
        return IdempotencyRecord::query()->create([
            'operation' => $operation,
            'idempotency_key' => $idempotencyKey,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'response_fingerprint' => $fingerprint,
        ]);
    }
}
