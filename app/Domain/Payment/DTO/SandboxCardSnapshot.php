<?php

namespace App\Domain\Payment\DTO;

use App\Domain\Payment\Enums\SandboxCardBehavior;

readonly class SandboxCardSnapshot
{
    public function __construct(
        public string $token,
        public SandboxCardBehavior $behavior,
        public int $balanceValue,
        public string $currency,
        public string $brand,
        public string $lastFour,
        public string $maskedPan,
        public bool $isExpired,
        public bool $isBlocked,
    ) {}

    public function hasSufficientBalance(int $amountValue): bool
    {
        return $this->balanceValue >= $amountValue;
    }
}
