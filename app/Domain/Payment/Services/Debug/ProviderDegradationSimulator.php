<?php

namespace App\Domain\Payment\Services\Debug;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\FailureMode;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\RetryableMessageException;

class ProviderDegradationSimulator
{
    public function __construct(
        private readonly FailureModeManager $failureModeManager,
    ) {}

    public function beforeProcessing(PaymentOperation $operation): void
    {
        match ($this->failureModeManager->current()) {
            FailureMode::ProcessingDelay => $this->applyProcessingDelay(),
            FailureMode::Timeout => throw new RetryableMessageException(sprintf(
                'Simulated processing timeout during %s.',
                $operation->value,
            )),
            FailureMode::ProviderUnavailable => throw new RetryableMessageException(sprintf(
                'Simulated provider unavailable during %s.',
                $operation->value,
            )),
            default => null,
        };
    }

    public function authorizationDeclineReason(?int $randomRoll = null): ?DeclineReasonCode
    {
        return match ($this->failureModeManager->current()) {
            FailureMode::AlwaysDecline => DeclineReasonCode::Declined,
            FailureMode::RandomDecline => $this->shouldRandomlyDecline($randomRoll)
                ? DeclineReasonCode::Declined
                : null,
            default => null,
        };
    }

    public function captureDeclineReason(?int $randomRoll = null): ?DeclineReasonCode
    {
        return match ($this->failureModeManager->current()) {
            FailureMode::AlwaysDecline, FailureMode::CaptureFailure => DeclineReasonCode::CaptureFailed,
            FailureMode::RandomDecline => $this->shouldRandomlyDecline($randomRoll)
                ? DeclineReasonCode::CaptureFailed
                : null,
            default => null,
        };
    }

    public function refundDeclineReason(?int $randomRoll = null): ?DeclineReasonCode
    {
        return match ($this->failureModeManager->current()) {
            FailureMode::AlwaysDecline, FailureMode::RefundFailure => DeclineReasonCode::RefundFailed,
            FailureMode::RandomDecline => $this->shouldRandomlyDecline($randomRoll)
                ? DeclineReasonCode::RefundFailed
                : null,
            default => null,
        };
    }

    public function assertPublishAllowed(): void
    {
        if ($this->failureModeManager->current() === FailureMode::PublishFailure) {
            throw new RetryableMessageException('Simulated payment event publish failure.');
        }
    }

    public function shouldDuplicatePublishedResponse(): bool
    {
        return $this->failureModeManager->current() === FailureMode::DuplicateResponse;
    }

    private function applyProcessingDelay(): void
    {
        $delayMs = (int) config('payment_mock.degradation.processing_delay_ms');

        if ($delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }

    private function shouldRandomlyDecline(?int $randomRoll): bool
    {
        $roll = $randomRoll ?? random_int(0, 1);

        return $roll === 0;
    }
}
