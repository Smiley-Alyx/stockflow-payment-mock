<?php

namespace App\Infrastructure\Observability;

use App\Domain\Payment\Enums\FailureMode;
use App\Domain\Payment\Services\Debug\FailureModeManager;
use App\Infrastructure\Observability\Prometheus\PrometheusRegistry;

class PaymentMetricsRecorder
{
    public function __construct(
        private readonly PrometheusRegistry $registry,
        private readonly FailureModeManager $failureModeManager,
    ) {}

    public function recordRequestProcessed(
        string $operation,
        string $routingKey,
        string $outcome,
        float $durationSeconds,
        bool $idempotentReplay = false,
    ): void {
        $labels = [
            'operation' => $operation,
            'routing_key' => $routingKey,
            'outcome' => $outcome,
        ];

        $this->registry->incrementCounter('payment_requests_total', $labels);
        $this->registry->observeHistogram('payment_processing_duration_seconds', $durationSeconds, [
            'operation' => $operation,
            'outcome' => $outcome,
        ]);

        if ($idempotentReplay) {
            $this->registry->incrementCounter('payment_idempotent_replays_total', [
                'operation' => $operation,
            ]);
        }
    }

    public function recordEventPublished(
        string $routingKey,
        bool $idempotentReplay,
        bool $duplicate = false,
    ): void {
        $this->registry->incrementCounter('payment_events_published_total', [
            'routing_key' => $routingKey,
            'idempotent_replay' => $idempotentReplay ? 'true' : 'false',
        ]);

        if ($duplicate) {
            $this->registry->incrementCounter('payment_event_duplicates_total', [
                'routing_key' => $routingKey,
            ]);
        }
    }

    public function recordRetryScheduled(string $routingKey): void
    {
        $this->registry->incrementCounter('payment_request_retries_total', [
            'routing_key' => $routingKey,
        ]);
    }

    public function recordRetryRequeued(string $routingKey): void
    {
        $this->registry->incrementCounter('payment_request_retry_requeues_total', [
            'routing_key' => $routingKey,
        ]);
    }

    public function recordDlq(string $routingKey, string $reason): void
    {
        $this->registry->incrementCounter('payment_request_dlq_total', [
            'routing_key' => $routingKey,
            'reason' => $reason,
        ]);
    }

    public function recordInvalidMessage(string $routingKey): void
    {
        $this->registry->incrementCounter('payment_invalid_messages_total', [
            'routing_key' => $routingKey,
        ]);
    }

    public function recordDlqRequeued(int $count): void
    {
        $this->registry->incrementCounter('payment_dlq_requeued_total', [], (float) $count);
    }

    public function snapshotFailureMode(): void
    {
        $current = $this->failureModeManager->current();

        foreach (FailureMode::cases() as $mode) {
            $this->registry->setGauge('payment_failure_mode_active', [
                'mode' => $mode->value,
            ], $mode === $current ? 1 : 0);
        }
    }
}
