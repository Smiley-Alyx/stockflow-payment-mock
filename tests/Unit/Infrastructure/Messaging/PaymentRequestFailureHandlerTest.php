<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\PublishedEventConflictException;
use App\Infrastructure\Messaging\RabbitMq\MessageRetryPolicy;
use App\Infrastructure\Messaging\RabbitMq\PaymentDlqPublisher;
use App\Infrastructure\Messaging\RabbitMq\PaymentRequestFailureHandler;
use App\Infrastructure\Messaging\RabbitMq\PaymentRequestRetryPublisher;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\TestCase;

class PaymentRequestFailureHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_schedules_retry_for_transient_failure(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->once()->with(7);

        $retryPublisher = Mockery::mock(PaymentRequestRetryPublisher::class);
        $retryPublisher->shouldReceive('publish')
            ->once()
            ->with($channel, Mockery::type(AMQPMessage::class), 1);

        $dlqPublisher = Mockery::mock(PaymentDlqPublisher::class);
        $dlqPublisher->shouldNotReceive('publish');

        $handler = new PaymentRequestFailureHandler(
            $this->retryPolicy(maxRetryAttempts: 3),
            $retryPublisher,
            $dlqPublisher,
        );

        $message = new AMQPMessage('{}');
        $message->setDeliveryTag(7);

        $handler->handleProcessingFailure($channel, $message, new RuntimeException('temporary'));
    }

    public function test_moves_non_retryable_failure_to_dlq(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->once()->with(9);

        $retryPublisher = Mockery::mock(PaymentRequestRetryPublisher::class);
        $retryPublisher->shouldNotReceive('publish');

        $dlqPublisher = Mockery::mock(PaymentDlqPublisher::class);
        $dlqPublisher->shouldReceive('publish')->once();

        $handler = new PaymentRequestFailureHandler(
            $this->retryPolicy(maxRetryAttempts: 3),
            $retryPublisher,
            $dlqPublisher,
        );

        $message = new AMQPMessage('{}');
        $message->setDeliveryTag(9);

        $handler->handleProcessingFailure(
            $channel,
            $message,
            PublishedEventConflictException::forOperation('payment.authorization', 'pay_1', 'idem_1'),
        );
    }

    public function test_moves_exhausted_retries_to_dlq(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->once()->with(11);

        $retryPublisher = Mockery::mock(PaymentRequestRetryPublisher::class);
        $retryPublisher->shouldNotReceive('publish');

        $dlqPublisher = Mockery::mock(PaymentDlqPublisher::class);
        $dlqPublisher->shouldReceive('publish')->once();

        $handler = new PaymentRequestFailureHandler(
            $this->retryPolicy(maxRetryAttempts: 3),
            $retryPublisher,
            $dlqPublisher,
        );

        $message = new AMQPMessage('{}', [
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                'x-retry-count' => '3',
            ]),
        ]);
        $message->setDeliveryTag(11);

        $handler->handleProcessingFailure($channel, $message, new RuntimeException('still failing'));
    }

    public function test_rejects_invalid_messages_without_retry(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_reject')->once()->with(3, false);

        $handler = new PaymentRequestFailureHandler(
            $this->retryPolicy(maxRetryAttempts: 3),
            Mockery::mock(PaymentRequestRetryPublisher::class),
            Mockery::mock(PaymentDlqPublisher::class),
        );

        $message = new AMQPMessage('{}');
        $message->setDeliveryTag(3);

        $handler->handleInvalidMessage($channel, $message, new InvalidMessageException('bad payload'));
    }

    private function retryPolicy(int $maxRetryAttempts): MessageRetryPolicy
    {
        return new MessageRetryPolicy(new RabbitMqConfig(
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
            maxRetryAttempts: $maxRetryAttempts,
            retryDelayMs: 0,
        ));
    }
}
