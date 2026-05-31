<?php

namespace App\Domain\Payment\DTO;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;

readonly class AuthorizationResult
{
    public function __construct(
        public Payment $payment,
        public PaymentAttempt $attempt,
        public ?Authorization $authorization,
        public bool $approved,
        public bool $idempotentReplay,
        public ?DeclineReasonCode $declineReason = null,
    ) {}

    public function isDeclined(): bool
    {
        return ! $this->approved;
    }
}
