<?php

namespace App\Domain\Payment\Services\Sandbox;

use App\Domain\Payment\DTO\SandboxCardSnapshot;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\SandboxCardBehavior;

class SandboxCardBehaviorEvaluator
{
    public function authorizationDeclineReason(
        SandboxCardSnapshot $card,
        int $amountValue,
        ?int $randomRoll = null,
    ): ?DeclineReasonCode {
        if ($card->isExpired || $card->behavior === SandboxCardBehavior::Expired) {
            return DeclineReasonCode::CardExpired;
        }

        if ($card->isBlocked || $card->behavior === SandboxCardBehavior::Blocked) {
            return DeclineReasonCode::CardBlocked;
        }

        if ($card->behavior->simulatesProviderUnavailable()) {
            return DeclineReasonCode::ProviderUnavailable;
        }

        if ($card->behavior === SandboxCardBehavior::RandomDecline) {
            $roll = $randomRoll ?? random_int(0, 1);

            return $roll === 0
                ? DeclineReasonCode::Declined
                : null;
        }

        if ($card->behavior === SandboxCardBehavior::InsufficientFunds || ! $card->hasSufficientBalance($amountValue)) {
            return DeclineReasonCode::InsufficientFunds;
        }

        return null;
    }

    public function captureDeclineReason(SandboxCardSnapshot $card): ?DeclineReasonCode
    {
        if ($card->behavior->failsCapture()) {
            return DeclineReasonCode::CaptureFailed;
        }

        return null;
    }

    public function refundDeclineReason(SandboxCardSnapshot $card): ?DeclineReasonCode
    {
        if ($card->behavior->failsRefund()) {
            return DeclineReasonCode::RefundFailed;
        }

        return null;
    }
}
