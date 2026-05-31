<?php

namespace Tests\Integration\RabbitMq;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsSandboxCards;
use Tests\Support\Messaging\RabbitMqIntegrationHarness;
use Tests\TestCase;

abstract class RabbitMqIntegrationTestCase extends TestCase
{
    use RefreshDatabase;
    use SeedsSandboxCards;

    protected RabbitMqIntegrationHarness $rabbitMq;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('INTEGRATION_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Integration tests are disabled. Run make test-integration.');
        }

        if (! RabbitMqIntegrationHarness::isBrokerAvailable()) {
            $this->fail('RabbitMQ broker is required for integration tests. Start it with make docker-up.');
        }

        config([
            'payment_mock.rabbitmq.publish_events' => true,
            'payment_mock.rabbitmq.setup_topology' => true,
        ]);

        $this->seedSandboxCards();

        $this->app->singleton(RabbitMqIntegrationHarness::class, fn ($app): RabbitMqIntegrationHarness => new RabbitMqIntegrationHarness(
            $app->make(\App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig::class),
            $app->make(\App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory::class),
            $app->make(\App\Infrastructure\Messaging\RabbitMq\RabbitMqTopologyManager::class),
            $app->make(\App\Infrastructure\Messaging\RabbitMq\PaymentRequestProcessor::class),
        ));

        $this->rabbitMq = $this->app->make(RabbitMqIntegrationHarness::class);
        $this->rabbitMq->setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->rabbitMq)) {
            $this->rabbitMq->tearDown();
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function requestHeaders(
        string $suffix,
        string $idempotencyKey,
        string $correlationId = 'cor_integration_001',
    ): array {
        return [
            'message_id' => 'msg_'.$suffix,
            'correlation_id' => $correlationId,
            'causation_id' => 'msg_parent_'.$suffix,
            'idempotency_key' => $idempotencyKey,
            'schema_version' => 'v1',
            'occurred_at' => '2026-05-31T10:00:00Z',
            'producer' => 'stockflow-market',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function authorizationPayload(string $paymentId, array $overrides = []): array
    {
        return array_merge([
            'payment_id' => $paymentId,
            'order_id' => 'ord_'.$paymentId,
            'customer_id' => 'cust_'.$paymentId,
            'amount' => ['value' => 12_990, 'currency' => 'EUR'],
            'payment_method' => ['type' => 'card', 'token' => 'tok_approved_visa'],
            'capture_mode' => 'manual',
        ], $overrides);
    }

    protected function uniquePaymentId(string $prefix): string
    {
        return $prefix.'_'.strtolower(Str::replace('-', '', Str::ulid()));
    }
}
