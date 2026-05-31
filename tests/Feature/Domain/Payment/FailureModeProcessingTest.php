<?php

namespace Tests\Feature\Domain\Payment;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\FailureMode;
use App\Domain\Payment\Services\Debug\FailureModeManager;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\RetryableMessageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthorizesPayments;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class FailureModeProcessingTest extends TestCase
{
    use AuthorizesPayments;
    use RefreshDatabase;
    use SeedsSandboxCards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
    }

    public function test_always_decline_failure_mode_declines_approved_card(): void
    {
        $this->app->make(FailureModeManager::class)->set(FailureMode::AlwaysDecline);

        $result = $this->authorizePayment('pay_failure_mode_decline_1', idempotencyKey: 'idem-failure-decline-1');

        $this->assertFalse($result->approved);
        $this->assertSame(DeclineReasonCode::Declined, $result->declineReason);
    }

    public function test_provider_unavailable_failure_mode_throws_retryable_exception(): void
    {
        $this->app->make(FailureModeManager::class)->set(FailureMode::ProviderUnavailable);

        $this->expectException(RetryableMessageException::class);

        $this->authorizePayment('pay_failure_mode_unavailable_1', idempotencyKey: 'idem-failure-unavailable-1');
    }

    public function test_capture_failure_mode_fails_capture_after_successful_authorization(): void
    {
        $failureModeManager = $this->app->make(FailureModeManager::class);
        $paymentId = 'pay_failure_mode_capture_1';

        $this->authorizePayment($paymentId, idempotencyKey: 'idem-failure-cap-auth-1');

        $failureModeManager->set(FailureMode::CaptureFailure);

        $result = $this->app->make(\App\Domain\Payment\Services\Capture\PaymentCaptureService::class)->capture(
            new \App\Domain\Payment\DTO\CaptureRequest(
                paymentId: $paymentId,
                idempotencyKey: 'idem-failure-cap-1',
            ),
        );

        $this->assertFalse($result->completed);
        $this->assertSame(DeclineReasonCode::CaptureFailed, $result->failureReason);
    }
}
