<?php

namespace Tests\Unit\Domain\Payment\Refund;

use App\Domain\Payment\DTO\CaptureRequest;
use App\Domain\Payment\DTO\RefundRequest;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentStateException;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Domain\Payment\Services\Refund\PaymentRefundService;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthorizesPayments;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class PaymentRefundServiceTest extends TestCase
{
    use AuthorizesPayments;
    use RefreshDatabase;
    use SeedsSandboxCards;

    private PaymentCaptureService $captureService;

    private PaymentRefundService $refundService;

    private SandboxBalanceService $balanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
        $this->captureService = $this->app->make(PaymentCaptureService::class);
        $this->refundService = $this->app->make(PaymentRefundService::class);
        $this->balanceService = $this->app->make(SandboxBalanceService::class);
    }

    public function test_full_refund_after_capture(): void
    {
        $paymentId = 'pay_refund_full_1';
        $amount = 6_000;
        $balanceBefore = $this->balanceService->currentBalance('tok_approved_visa');

        $this->authorizeAndCapture($paymentId, $amount);

        $balanceAfterCapture = $this->balanceService->currentBalance('tok_approved_visa');

        $result = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-full-1',
        ));

        $this->assertTrue($result->completed);
        $this->assertSame(PaymentStatus::Refunded, $result->payment->status);
        $this->assertSame(RefundStatus::Completed, $result->refund?->status);
        $this->assertSame($amount, $result->refund?->amount_value);
        $this->assertSame($balanceBefore, $this->balanceService->currentBalance('tok_approved_visa'));
        $this->assertLessThan($balanceBefore, $balanceAfterCapture);
    }

    public function test_partial_refund_then_remaining_refund(): void
    {
        $paymentId = 'pay_refund_partial_1';
        $amount = 10_000;

        $this->authorizeAndCapture($paymentId, $amount);

        $first = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-partial-1',
            amountValue: 4_000,
        ));

        $second = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-partial-2',
            amountValue: 6_000,
        ));

        $this->assertTrue($first->completed);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $first->payment->status);

        $this->assertTrue($second->completed);
        $this->assertSame(PaymentStatus::Refunded, $second->payment->status);
    }

    public function test_refund_fails_when_payment_not_captured(): void
    {
        $paymentId = 'pay_refund_not_captured_1';

        $this->authorizePayment($paymentId, idempotencyKey: 'idem-auth-refund-nc-1');

        $result = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-not-captured-1',
        ));

        $this->assertFalse($result->completed);
        $this->assertSame(DeclineReasonCode::RefundFailed, $result->failureReason);
        $this->assertSame(PaymentStatus::Authorized, $result->payment->status);
        $this->assertNull($result->refund);
    }

    public function test_refund_fails_when_amount_exceeds_captured_amount(): void
    {
        $paymentId = 'pay_refund_exceeds_1';
        $amount = 3_000;

        $this->authorizeAndCapture($paymentId, $amount);

        $result = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-exceeds-1',
            amountValue: 5_000,
        ));

        $this->assertFalse($result->completed);
        $this->assertSame(DeclineReasonCode::RefundFailed, $result->failureReason);
        $this->assertSame('Refund amount exceeds captured amount', $result->attempt->reason_message);
        $this->assertSame(PaymentStatus::Captured, $result->payment->status);
    }

    public function test_refund_fails_for_refund_failure_token(): void
    {
        $paymentId = 'pay_refund_failure_token_1';
        $amount = 2_500;

        $this->authorizeAndCapture($paymentId, $amount, 'tok_refund_failure');

        $result = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-fail-token-1',
        ));

        $this->assertFalse($result->completed);
        $this->assertSame(DeclineReasonCode::RefundFailed, $result->failureReason);
        $this->assertSame(RefundStatus::Failed, $result->refund?->status);
    }

    public function test_duplicate_refund_request_is_idempotent(): void
    {
        $paymentId = 'pay_refund_idempotent_1';

        $this->authorizeAndCapture($paymentId, 1_500);

        $first = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-idem-1',
        ));

        $second = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-idem-1',
        ));

        $this->assertTrue($first->completed);
        $this->assertTrue($second->completed);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame($first->refund?->id, $second->refund?->id);
    }

    public function test_refund_on_declined_payment_is_rejected(): void
    {
        $paymentId = 'pay_refund_declined_1';

        $auth = $this->authorizePayment(
            $paymentId,
            token: 'tok_blocked_card',
            idempotencyKey: 'idem-auth-refund-declined-1',
        );

        $this->assertFalse($auth->approved);

        $result = $this->refundService->refund(new RefundRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-refund-declined-1',
        ));

        $this->assertFalse($result->completed);
        $this->assertSame(PaymentStatus::AuthorizationDeclined, $result->payment->status);
    }

    private function authorizeAndCapture(
        string $paymentId,
        int $amount,
        string $token = 'tok_approved_visa',
    ): void {
        $this->authorizePayment(
            $paymentId,
            token: $token,
            amount: $amount,
            idempotencyKey: 'idem-auth-'.$paymentId,
        );

        $this->captureService->capture(new CaptureRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-cap-'.$paymentId,
        ));
    }
}
