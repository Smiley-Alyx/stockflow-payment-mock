<?php

namespace Tests\Unit\Domain\Payment\Capture;

use App\Domain\Payment\DTO\CaptureRequest;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentStateException;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthorizesPayments;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class PaymentCaptureServiceTest extends TestCase
{
    use AuthorizesPayments;
    use RefreshDatabase;
    use SeedsSandboxCards;

    private PaymentCaptureService $service;

    private SandboxBalanceService $balanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
        $this->service = $this->app->make(PaymentCaptureService::class);
        $this->balanceService = $this->app->make(SandboxBalanceService::class);
    }

    public function test_capture_completes_authorized_payment(): void
    {
        $paymentId = 'pay_capture_success_1';
        $amount = 8_500;

        $this->authorizePayment($paymentId, amount: $amount, idempotencyKey: 'idem-auth-cap-1');

        $result = $this->service->capture(new CaptureRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-cap-success-1',
        ));

        $this->assertTrue($result->completed);
        $this->assertSame(PaymentStatus::Captured, $result->payment->status);
        $this->assertSame(CaptureStatus::Completed, $result->capture?->status);
        $this->assertSame($amount, $result->capture?->amount_value);
    }

    public function test_capture_fails_for_capture_failure_token_and_releases_balance(): void
    {
        $paymentId = 'pay_capture_failure_1';
        $amount = 4_000;
        $balanceBefore = $this->balanceService->currentBalance('tok_capture_failure');

        $this->authorizePayment(
            $paymentId,
            token: 'tok_capture_failure',
            amount: $amount,
            idempotencyKey: 'idem-auth-cap-fail-1',
        );

        $balanceAfterAuth = $this->balanceService->currentBalance('tok_capture_failure');

        $result = $this->service->capture(new CaptureRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-cap-fail-1',
        ));

        $this->assertFalse($result->completed);
        $this->assertSame(DeclineReasonCode::CaptureFailed, $result->failureReason);
        $this->assertSame(PaymentStatus::CaptureFailed, $result->payment->status);
        $this->assertSame($balanceBefore, $this->balanceService->currentBalance('tok_capture_failure'));
        $this->assertLessThan($balanceBefore, $balanceAfterAuth);
    }

    public function test_capture_requires_authorized_payment(): void
    {
        $paymentId = 'pay_capture_invalid_state_1';

        $this->authorizePayment(
            $paymentId,
            token: 'tok_insufficient_funds',
            amount: 50_000,
            idempotencyKey: 'idem-auth-cap-invalid-1',
        );

        $this->expectException(InvalidPaymentStateException::class);

        $this->service->capture(new CaptureRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-cap-invalid-state-1',
        ));
    }

    public function test_duplicate_capture_request_is_idempotent(): void
    {
        $paymentId = 'pay_capture_idempotent_1';

        $this->authorizePayment($paymentId, idempotencyKey: 'idem-auth-cap-idem-1');

        $first = $this->service->capture(new CaptureRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-cap-idem-1',
        ));

        $second = $this->service->capture(new CaptureRequest(
            paymentId: $paymentId,
            idempotencyKey: 'idem-cap-idem-1',
        ));

        $this->assertTrue($first->completed);
        $this->assertTrue($second->completed);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame($first->capture?->id, $second->capture?->id);
    }
}
