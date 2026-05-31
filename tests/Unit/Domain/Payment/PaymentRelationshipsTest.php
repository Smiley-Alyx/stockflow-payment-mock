<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentAttemptType;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_has_related_attempts_authorization_capture_and_refund(): void
    {
        $payment = Payment::factory()->create();

        $attempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'type' => PaymentAttemptType::Authorization,
            'status' => PaymentAttemptStatus::Completed,
        ]);

        $authorization = Authorization::factory()->create([
            'authorization_id' => 'auth_relationship_1',
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt->id,
            'amount_value' => $payment->amount_value,
            'amount_currency' => $payment->amount_currency,
            'status' => AuthorizationStatus::Approved,
        ]);

        $captureAttempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'type' => PaymentAttemptType::Capture,
            'status' => PaymentAttemptStatus::Completed,
        ]);

        $capture = Capture::factory()->create([
            'capture_id' => 'cap_relationship_1',
            'payment_id' => $payment->id,
            'payment_attempt_id' => $captureAttempt->id,
            'authorization_id' => $authorization->id,
            'amount_value' => $payment->amount_value,
            'amount_currency' => $payment->amount_currency,
            'status' => CaptureStatus::Completed,
        ]);

        $refundAttempt = PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'type' => PaymentAttemptType::Refund,
            'status' => PaymentAttemptStatus::Completed,
        ]);

        Refund::factory()->create([
            'refund_id' => 'ref_relationship_1',
            'payment_id' => $payment->id,
            'payment_attempt_id' => $refundAttempt->id,
            'capture_id' => $capture->id,
            'amount_value' => $payment->amount_value,
            'amount_currency' => $payment->amount_currency,
            'status' => RefundStatus::Completed,
        ]);

        $payment->refresh();

        $this->assertCount(3, $payment->attempts);
        $this->assertTrue($payment->authorization()->is($authorization));
        $this->assertCount(1, $payment->captures);
        $this->assertCount(1, $payment->refunds);
        $this->assertTrue($attempt->authorization()->is($authorization));
        $this->assertTrue($captureAttempt->capture()->is($capture));
        $this->assertTrue($refundAttempt->refund()->is($payment->refunds->first()));
    }
}
