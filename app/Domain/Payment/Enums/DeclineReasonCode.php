<?php

namespace App\Domain\Payment\Enums;

enum DeclineReasonCode: string
{
    case InsufficientFunds = 'INSUFFICIENT_FUNDS';
    case CardExpired = 'CARD_EXPIRED';
    case CardBlocked = 'CARD_BLOCKED';
    case InvalidCardToken = 'INVALID_CARD_TOKEN';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    case ProcessingTimeout = 'PROCESSING_TIMEOUT';
    case CaptureFailed = 'CAPTURE_FAILED';
    case RefundFailed = 'REFUND_FAILED';
    case Declined = 'DECLINED';

    public function message(): string
    {
        return match ($this) {
            self::InsufficientFunds => 'Sandbox card has insufficient funds',
            self::CardExpired => 'Sandbox card is expired',
            self::CardBlocked => 'Sandbox card is blocked',
            self::InvalidCardToken => 'Sandbox card token is invalid',
            self::ProviderUnavailable => 'Payment provider is temporarily unavailable',
            self::ProcessingTimeout => 'Payment processing timed out',
            self::CaptureFailed => 'Sandbox capture failed',
            self::RefundFailed => 'Sandbox refund failed',
            self::Declined => 'Sandbox payment was declined',
        };
    }
}
