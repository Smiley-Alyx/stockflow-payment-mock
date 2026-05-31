<?php

namespace App\Domain\Payment\Services\Idempotency;

use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Models\IdempotencyRecord;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;

class PaymentIdempotencyService
{
    public function findAuthorizationRecord(string $paymentId, string $idempotencyKey): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('operation', IdempotencyRecord::OPERATION_AUTHORIZATION)
            ->where('payment_id', $paymentId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function storeAuthorizationRecord(
        Payment $payment,
        PaymentAttempt $attempt,
        string $idempotencyKey,
        string $fingerprint,
    ): IdempotencyRecord {
        return IdempotencyRecord::query()->create([
            'operation' => IdempotencyRecord::OPERATION_AUTHORIZATION,
            'idempotency_key' => $idempotencyKey,
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'response_fingerprint' => $fingerprint,
        ]);
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

}
