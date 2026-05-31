<?php

namespace App\Domain\Payment\DTO;

readonly class CaptureRequest
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $paymentId,
        public string $idempotencyKey,
        public ?int $amountValue = null,
        public ?string $amountCurrency = null,
        public ?string $messageId = null,
        public ?string $correlationId = null,
        public ?array $metadata = null,
    ) {}
}
