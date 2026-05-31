<?php

namespace App\Domain\Payment\Enums;

enum PaymentStatus: string
{
    case Created = 'created';
    case AuthorizationPending = 'authorization_pending';
    case Authorized = 'authorized';
    case AuthorizationDeclined = 'authorization_declined';
    case CapturePending = 'capture_pending';
    case Captured = 'captured';
    case CaptureFailed = 'capture_failed';
    case RefundPending = 'refund_pending';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case RefundFailed = 'refund_failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::AuthorizationDeclined,
            self::Captured,
            self::CaptureFailed,
            self::Refunded,
            self::RefundFailed,
            self::Cancelled,
        ], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @return list<self>
     */
    private function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::AuthorizationPending, self::Cancelled],
            self::AuthorizationPending => [
                self::Authorized,
                self::AuthorizationDeclined,
                self::Cancelled,
            ],
            self::Authorized => [self::CapturePending, self::Cancelled],
            self::AuthorizationDeclined => [],
            self::CapturePending => [self::Captured, self::CaptureFailed],
            self::Captured => [self::RefundPending],
            self::CaptureFailed => [],
            self::RefundPending => [
                self::PartiallyRefunded,
                self::Refunded,
                self::RefundFailed,
            ],
            self::PartiallyRefunded => [
                self::RefundPending,
                self::Refunded,
            ],
            self::Refunded, self::RefundFailed, self::Cancelled => [],
        };
    }
}
