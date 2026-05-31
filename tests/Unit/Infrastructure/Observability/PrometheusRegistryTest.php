<?php

namespace Tests\Unit\Infrastructure\Observability;

use App\Infrastructure\Observability\Prometheus\PrometheusRegistry;
use Tests\TestCase;

class PrometheusRegistryTest extends TestCase
{
    public function test_renders_counter_and_histogram_in_prometheus_text_format(): void
    {
        $registry = new PrometheusRegistry;

        $registry->incrementCounter('payment_requests_total', [
            'operation' => 'authorization',
            'routing_key' => 'payment.authorization.requested.v1',
            'outcome' => 'approved',
        ]);

        $registry->observeHistogram('payment_processing_duration_seconds', 0.042, [
            'operation' => 'authorization',
            'outcome' => 'approved',
        ]);

        $output = $registry->render();

        $this->assertStringContainsString('# TYPE payment_requests_total counter', $output);
        $this->assertStringContainsString(
            'payment_requests_total{operation="authorization",outcome="approved",routing_key="payment.authorization.requested.v1"} 1',
            $output,
        );
        $this->assertStringContainsString('# TYPE payment_processing_duration_seconds histogram', $output);
        $this->assertStringContainsString('payment_processing_duration_seconds_bucket', $output);
        $this->assertStringContainsString('payment_processing_duration_seconds_sum', $output);
        $this->assertStringContainsString('payment_processing_duration_seconds_count', $output);
    }
}
