<?php

namespace App\Domain\Payment\DTO;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;

readonly class RefundResult
{
    public function __construct(
        public Payment $payment,
        public PaymentAttempt $attempt,
        public ?Refund $refund,
        public bool $completed,
        public bool $idempotentReplay,
        public ?DeclineReasonCode $failureReason = null,
    ) {}

    public function isFailed(): bool
    {
        return ! $this->completed;
    }
}
