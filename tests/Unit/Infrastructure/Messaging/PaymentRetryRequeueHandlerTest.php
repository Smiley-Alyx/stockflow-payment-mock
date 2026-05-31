<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\PaymentRetryRequeueHandler;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

class PaymentRetryRequeueHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_republishes_retry_message_to_main_exchange(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')
            ->once()
            ->with(
                Mockery::type(AMQPMessage::class),
                'stockflow.payment',
                'payment.authorization.requested.v1',
            );
        $channel->shouldReceive('basic_ack')->once()->with(5);

        $handler = new PaymentRetryRequeueHandler($this->config(retryDelayMs: 0));

        $message = new AMQPMessage('{"payment_id":"pay_1"}', [
            'application_headers' => new AMQPTable([
                'x-retry-count' => '1',
                'x-original-routing-key' => 'payment.authorization.requested.v1',
            ]),
        ]);
        $message->setDeliveryTag(5);

        $handler->handle($channel, $message);
    }

    public function test_nacks_message_when_retry_delay_has_not_elapsed(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_nack')->once()->with(8, false, true);
        $channel->shouldNotReceive('basic_publish');

        $handler = new PaymentRetryRequeueHandler($this->config(retryDelayMs: 5000));

        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable([
                'x-retry-count' => '1',
                'x-original-routing-key' => 'payment.authorization.requested.v1',
                'x-retry-after' => (string) ((int) (microtime(true) * 1000) + 60_000),
            ]),
        ]);
        $message->setDeliveryTag(8);

        $handler->handle($channel, $message);
    }

    private function config(int $retryDelayMs): RabbitMqConfig
    {
        return new RabbitMqConfig(
            host: '127.0.0.1',
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
            consumerTimeoutSeconds: 30,
            setupTopology: true,
            maxRetryAttempts: 3,
            retryDelayMs: $retryDelayMs,
        );
    }
}
