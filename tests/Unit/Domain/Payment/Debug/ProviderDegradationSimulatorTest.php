<?php

namespace Tests\Unit\Domain\Payment\Debug;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\FailureMode;
use App\Domain\Payment\Services\Debug\FailureModeManager;
use App\Domain\Payment\Services\Debug\PaymentOperation;
use App\Domain\Payment\Services\Debug\ProviderDegradationSimulator;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\RetryableMessageException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProviderDegradationSimulatorTest extends TestCase
{
    private FailureModeManager $failureModeManager;

    private ProviderDegradationSimulator $simulator;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['payment_mock.degradation.processing_delay_ms' => 0]);

        $this->failureModeManager = $this->app->make(FailureModeManager::class);
        $this->simulator = $this->app->make(ProviderDegradationSimulator::class);
    }

    public function test_always_decline_mode_declines_authorization(): void
    {
        $this->failureModeManager->set(FailureMode::AlwaysDecline);

        $this->assertSame(
            DeclineReasonCode::Declined,
            $this->simulator->authorizationDeclineReason(),
        );
        $this->assertSame(
            DeclineReasonCode::CaptureFailed,
            $this->simulator->captureDeclineReason(),
        );
        $this->assertSame(
            DeclineReasonCode::RefundFailed,
            $this->simulator->refundDeclineReason(),
        );
    }

    public function test_random_decline_mode_uses_roll_for_authorization(): void
    {
        $this->failureModeManager->set(FailureMode::RandomDecline);

        $this->assertSame(DeclineReasonCode::Declined, $this->simulator->authorizationDeclineReason(0));
        $this->assertNull($this->simulator->authorizationDeclineReason(1));
    }

    public function test_capture_failure_mode_only_affects_capture(): void
    {
        $this->failureModeManager->set(FailureMode::CaptureFailure);

        $this->assertNull($this->simulator->authorizationDeclineReason());
        $this->assertSame(DeclineReasonCode::CaptureFailed, $this->simulator->captureDeclineReason());
        $this->assertNull($this->simulator->refundDeclineReason());
    }

    public function test_timeout_mode_throws_retryable_exception(): void
    {
        $this->failureModeManager->set(FailureMode::Timeout);

        $this->expectException(RetryableMessageException::class);

        $this->simulator->beforeProcessing(PaymentOperation::Authorization);
    }

    public function test_publish_failure_mode_blocks_event_publish(): void
    {
        $this->failureModeManager->set(FailureMode::PublishFailure);

        $this->expectException(RetryableMessageException::class);

        $this->simulator->assertPublishAllowed();
    }

    public function test_duplicate_response_mode_is_enabled_only_for_first_publish(): void
    {
        $this->failureModeManager->set(FailureMode::DuplicateResponse);

        $this->assertTrue($this->simulator->shouldDuplicatePublishedResponse());

        $this->failureModeManager->set(FailureMode::Normal);

        $this->assertFalse($this->simulator->shouldDuplicatePublishedResponse());
    }
}
