<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_terminal_statuses_are_marked_correctly(): void
    {
        $this->assertTrue(PaymentStatus::AuthorizationDeclined->isTerminal());
        $this->assertTrue(PaymentStatus::Captured->isTerminal());
        $this->assertTrue(PaymentStatus::Refunded->isTerminal());
        $this->assertFalse(PaymentStatus::AuthorizationPending->isTerminal());
        $this->assertFalse(PaymentStatus::Authorized->isTerminal());
    }

    #[DataProvider('allowedTransitionProvider')]
    public function test_allowed_status_transitions(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    #[DataProvider('disallowedTransitionProvider')]
    public function test_disallowed_status_transitions(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public static function allowedTransitionProvider(): array
    {
        return [
            'created to authorization pending' => [PaymentStatus::Created, PaymentStatus::AuthorizationPending],
            'authorization pending to authorized' => [PaymentStatus::AuthorizationPending, PaymentStatus::Authorized],
            'authorized to capture pending' => [PaymentStatus::Authorized, PaymentStatus::CapturePending],
            'capture pending to captured' => [PaymentStatus::CapturePending, PaymentStatus::Captured],
            'captured to refund pending' => [PaymentStatus::Captured, PaymentStatus::RefundPending],
            'refund pending to partially refunded' => [PaymentStatus::RefundPending, PaymentStatus::PartiallyRefunded],
            'partially refunded to refunded' => [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded],
        ];
    }

    public static function disallowedTransitionProvider(): array
    {
        return [
            'created to captured' => [PaymentStatus::Created, PaymentStatus::Captured],
            'declined to authorized' => [PaymentStatus::AuthorizationDeclined, PaymentStatus::Authorized],
            'captured to authorization pending' => [PaymentStatus::Captured, PaymentStatus::AuthorizationPending],
            'refunded to capture pending' => [PaymentStatus::Refunded, PaymentStatus::CapturePending],
        ];
    }
}
