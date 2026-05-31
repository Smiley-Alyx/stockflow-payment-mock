<?php

namespace App\Application\Mappers;

use App\Domain\Payment\DTO\SandboxCardSnapshot;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;

class PaymentMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        return [
            'payment_id' => $payment->payment_id,
            'order_id' => $payment->order_id,
            'customer_id' => $payment->customer_id,
            'status' => $payment->status->value,
            'amount' => [
                'value' => $payment->amount_value,
                'currency' => $payment->amount_currency,
            ],
            'capture_mode' => $payment->capture_mode->value,
            'payment_method' => [
                'type' => $payment->payment_method_type,
                'token' => $payment->payment_method_token,
            ],
            'metadata' => $payment->metadata ?? [],
            'authorized_at' => $payment->authorized_at?->toIso8601String(),
            'captured_at' => $payment->captured_at?->toIso8601String(),
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'updated_at' => $payment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentDetail(Payment $payment): array
    {
        $payment->loadMissing([
            'attempts',
            'authorization',
            'captures',
            'refunds',
        ]);

        return array_merge(self::payment($payment), [
            'authorization' => $payment->authorization
                ? self::authorization($payment->authorization)
                : null,
            'captures' => $payment->captures->map(fn (Capture $capture): array => self::capture($capture))->values()->all(),
            'refunds' => $payment->refunds->map(fn (Refund $refund): array => self::refund($refund))->values()->all(),
            'attempts' => $payment->attempts->map(fn (PaymentAttempt $attempt): array => self::attempt($attempt))->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function attempt(PaymentAttempt $attempt): array
    {
        return [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'type' => $attempt->type->value,
            'status' => $attempt->status->value,
            'idempotency_key' => $attempt->idempotency_key,
            'message_id' => $attempt->message_id,
            'correlation_id' => $attempt->correlation_id,
            'reason_code' => $attempt->reason_code,
            'reason_message' => $attempt->reason_message,
            'metadata' => $attempt->metadata ?? [],
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'created_at' => $attempt->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attemptDetail(PaymentAttempt $attempt): array
    {
        $attempt->loadMissing(['payment', 'authorization', 'capture', 'refund']);

        return array_merge(self::attempt($attempt), [
            'payment' => self::payment($attempt->payment),
            'authorization' => $attempt->authorization
                ? self::authorization($attempt->authorization)
                : null,
            'capture' => $attempt->capture
                ? self::capture($attempt->capture)
                : null,
            'refund' => $attempt->refund
                ? self::refund($attempt->refund)
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sandboxCard(SandboxCardSnapshot $card): array
    {
        return [
            'token' => $card->token,
            'behavior' => $card->behavior->value,
            'balance' => [
                'value' => $card->balanceValue,
                'currency' => $card->currency,
            ],
            'brand' => $card->brand,
            'last_four' => $card->lastFour,
            'masked_pan' => $card->maskedPan,
            'is_expired' => $card->isExpired,
            'is_blocked' => $card->isBlocked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function authorization(Authorization $authorization): array
    {
        return [
            'authorization_id' => $authorization->authorization_id,
            'status' => $authorization->status->value,
            'amount' => [
                'value' => $authorization->amount_value,
                'currency' => $authorization->amount_currency,
            ],
            'reason_code' => $authorization->reason_code,
            'reason_message' => $authorization->reason_message,
            'authorized_at' => $authorization->authorized_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function capture(Capture $capture): array
    {
        return [
            'capture_id' => $capture->capture_id,
            'status' => $capture->status->value,
            'amount' => [
                'value' => $capture->amount_value,
                'currency' => $capture->amount_currency,
            ],
            'reason_code' => $capture->reason_code,
            'reason_message' => $capture->reason_message,
            'captured_at' => $capture->captured_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function refund(Refund $refund): array
    {
        return [
            'refund_id' => $refund->refund_id,
            'status' => $refund->status->value,
            'amount' => [
                'value' => $refund->amount_value,
                'currency' => $refund->amount_currency,
            ],
            'reason_code' => $refund->reason_code,
            'reason_message' => $refund->reason_message,
            'refunded_at' => $refund->refunded_at?->toIso8601String(),
        ];
    }
}
