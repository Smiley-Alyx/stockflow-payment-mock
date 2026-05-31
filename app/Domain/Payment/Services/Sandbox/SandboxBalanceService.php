<?php

namespace App\Domain\Payment\Services\Sandbox;

use App\Domain\Payment\Models\SandboxCard;

class SandboxBalanceService
{
    public function reserve(string $token, int $amountValue): bool
    {
        $card = SandboxCard::query()
            ->where('token', $token)
            ->lockForUpdate()
            ->first();

        if ($card === null || $card->balance_value < $amountValue) {
            return false;
        }

        $card->balance_value -= $amountValue;
        $card->save();

        return true;
    }

    public function release(string $token, int $amountValue): void
    {
        $card = SandboxCard::query()
            ->where('token', $token)
            ->lockForUpdate()
            ->first();

        if ($card === null) {
            return;
        }

        $card->balance_value += $amountValue;
        $card->save();
    }

    public function currentBalance(string $token): int
    {
        return (int) SandboxCard::query()
            ->where('token', $token)
            ->value('balance_value');
    }
}
