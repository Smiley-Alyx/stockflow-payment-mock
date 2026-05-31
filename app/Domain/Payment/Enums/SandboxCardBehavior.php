<?php

namespace App\Domain\Payment\Enums;

enum SandboxCardBehavior: string
{
    case Approved = 'approved';
    case InsufficientFunds = 'insufficient_funds';
    case Expired = 'expired';
    case Blocked = 'blocked';
    case RandomDecline = 'random_decline';
    case ProcessingDelay = 'processing_delay';
    case ProviderUnavailable = 'provider_unavailable';
    case CaptureFailure = 'capture_failure';
    case RefundFailure = 'refund_failure';

    public function declineReason(): ?DeclineReasonCode
    {
        return match ($this) {
            self::InsufficientFunds => DeclineReasonCode::InsufficientFunds,
            self::Expired => DeclineReasonCode::CardExpired,
            self::Blocked => DeclineReasonCode::CardBlocked,
            self::ProviderUnavailable => DeclineReasonCode::ProviderUnavailable,
            self::ProcessingDelay => DeclineReasonCode::ProcessingTimeout,
            self::CaptureFailure => DeclineReasonCode::CaptureFailed,
            self::RefundFailure => DeclineReasonCode::RefundFailed,
            self::RandomDecline => DeclineReasonCode::Declined,
            self::Approved => null,
        };
    }

    public function processingDelayMs(): int
    {
        return match ($this) {
            self::ProcessingDelay => 2_000,
            default => 0,
        };
    }

    public function failsCapture(): bool
    {
        return $this === self::CaptureFailure;
    }

    public function failsRefund(): bool
    {
        return $this === self::RefundFailure;
    }

    public function simulatesProviderUnavailable(): bool
    {
        return $this === self::ProviderUnavailable;
    }
}
