<?php

namespace Tests\Feature\Http;

use App\Infrastructure\Observability\PaymentMetricsRecorder;
use Tests\TestCase;

class MetricsEndpointTest extends TestCase
{
    public function test_metrics_endpoint_returns_prometheus_text(): void
    {
        $recorder = $this->app->make(PaymentMetricsRecorder::class);
        $recorder->recordRequestProcessed(
            operation: 'authorization',
            routingKey: 'payment.authorization.requested.v1',
            outcome: 'approved',
            durationSeconds: 0.015,
        );

        $response = $this->get('/metrics');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        $this->assertStringContainsString('payment_requests_total', $response->getContent());
        $this->assertStringContainsString('payment_failure_mode_active', $response->getContent());
    }

    public function test_metrics_endpoint_is_disabled_when_config_is_off(): void
    {
        config(['payment_mock.observability.metrics_enabled' => false]);

        $this->get('/metrics')->assertNotFound();
    }
}
