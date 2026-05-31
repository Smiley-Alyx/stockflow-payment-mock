<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Enums\CaptureMode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_uses_marketplace_payment_id_as_primary_key(): void
    {
        $payment = Payment::factory()->create([
            'payment_id' => 'pay_marketplace_123',
        ]);

        $this->assertSame('pay_marketplace_123', $payment->id);
        $this->assertSame('pay_marketplace_123', $payment->payment_id);
    }

    public function test_payment_casts_status_and_capture_mode(): void
    {
        $payment = Payment::factory()->create([
            'status' => PaymentStatus::AuthorizationPending,
            'capture_mode' => CaptureMode::Manual,
        ]);

        $this->assertInstanceOf(PaymentStatus::class, $payment->status);
        $this->assertInstanceOf(CaptureMode::class, $payment->capture_mode);
        $this->assertSame(PaymentStatus::AuthorizationPending, $payment->status);
    }

    public function test_transition_to_updates_status_when_allowed(): void
    {
        $payment = Payment::factory()->create([
            'status' => PaymentStatus::Created,
        ]);

        $payment->transitionTo(PaymentStatus::AuthorizationPending);
        $payment->save();

        $this->assertSame(PaymentStatus::AuthorizationPending, $payment->fresh()->status);
    }

    public function test_transition_to_rejects_invalid_status_change(): void
    {
        $payment = Payment::factory()->create([
            'status' => PaymentStatus::Created,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $payment->transitionTo(PaymentStatus::Captured);
    }
}
