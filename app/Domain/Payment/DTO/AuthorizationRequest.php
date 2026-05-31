<?php

namespace App\Domain\Payment\DTO;

use App\Domain\Payment\Enums\CaptureMode;

readonly class AuthorizationRequest
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $customerId,
        public int $amountValue,
        public string $amountCurrency,
        public string $paymentMethodType,
        public string $paymentMethodToken,
        public CaptureMode $captureMode,
        public string $idempotencyKey,
        public ?string $messageId = null,
        public ?string $correlationId = null,
        public ?array $metadata = null,
        public ?int $randomRoll = null,
    ) {}
}
