<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use App\Infrastructure\Messaging\RabbitMq\PublishedPaymentEvent;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqMessagePublisher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;
use Tests\TestCase;

class RabbitMqMessagePublisherTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_reconnects_after_publish_failure(): void
    {
        $firstChannel = Mockery::mock(AMQPChannel::class);
        $firstChannel->shouldReceive('basic_publish')->once()->andThrow(new RuntimeException('closed'));
        $firstChannel->shouldReceive('is_open')->once()->andReturnFalse();

        $secondChannel = Mockery::mock(AMQPChannel::class);
        $secondChannel->shouldReceive('basic_publish')->once();

        $firstConnection = Mockery::mock(AMQPStreamConnection::class);
        $firstConnection->shouldReceive('channel')->once()->andReturn($firstChannel);
        $firstConnection->shouldReceive('isConnected')->once()->andReturnFalse();

        $secondConnection = Mockery::mock(AMQPStreamConnection::class);
        $secondConnection->shouldReceive('channel')->once()->andReturn($secondChannel);

        $connectionFactory = Mockery::mock(RabbitMqConnectionFactory::class);
        $connectionFactory->shouldReceive('create')
            ->twice()
            ->andReturn($firstConnection, $secondConnection);

        $publisher = new RabbitMqMessagePublisher($this->config(), $connectionFactory);

        try {
            $publisher->publish($this->event());
            $this->fail('Expected the first publish to fail.');
        } catch (RuntimeException) {
        }

        $publisher->publish($this->event());
    }

    private function config(): RabbitMqConfig
    {
        return new RabbitMqConfig(
            host: 'rabbitmq',
            port: 5672,
            user: 'stockflow',
            password: 'stockflow',
            vhost: '/',
            exchange: 'stockflow.payment',
            deadLetterExchange: 'stockflow.payment.dlx',
            requestsQueue: 'stockflow.payment.requests',
            retryQueue: 'stockflow.payment.requests.retry',
            dlq: 'stockflow.payment.requests.dlq',
            prefetchCount: 1,
            consumerTimeoutSeconds: 1,
            setupTopology: true,
            maxRetryAttempts: 3,
            retryDelayMs: 1000,
        );
    }

    private function event(): PublishedPaymentEvent
    {
        return new PublishedPaymentEvent(
            routingKey: 'payment.authorization.approved.v1',
            headers: new MessageHeaders(
                messageId: 'msg_demo_001',
                correlationId: 'corr_demo_001',
                causationId: 'msg_request_001',
                idempotencyKey: 'payment:authorization:pay_demo_001',
                schemaVersion: '1',
                occurredAt: '2026-06-01T10:15:01Z',
                producer: 'stockflow-payment-mock',
            ),
            payload: ['payment_id' => 'pay_demo_001'],
        );
    }
}
