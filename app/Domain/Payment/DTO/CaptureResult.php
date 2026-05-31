<?php

namespace App\Domain\Payment\DTO;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;

readonly class CaptureResult
{
    public function __construct(
        public Payment $payment,
        public PaymentAttempt $attempt,
        public ?Capture $capture,
        public bool $completed,
        public bool $idempotentReplay,
        public ?DeclineReasonCode $failureReason = null,
    ) {}

    public function isFailed(): bool
    {
        return ! $this->completed;
    }
}
