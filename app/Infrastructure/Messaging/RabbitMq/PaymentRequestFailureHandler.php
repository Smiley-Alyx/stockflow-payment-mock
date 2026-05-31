<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class PaymentRequestFailureHandler
{
    public function __construct(
        private readonly MessageRetryPolicy $retryPolicy,
        private readonly PaymentRequestRetryPublisher $retryPublisher,
        private readonly PaymentDlqPublisher $dlqPublisher,
    ) {}

    public function handleInvalidMessage(AMQPChannel $channel, AMQPMessage $message, InvalidMessageException $exception): void
    {
        Log::warning('invalid payment request message rejected', [
            'error' => $exception->getMessage(),
            'routing_key' => $message->getRoutingKey(),
        ]);

        $channel->basic_reject($message->getDeliveryTag(), false);
    }

    public function handleProcessingFailure(AMQPChannel $channel, AMQPMessage $message, Throwable $exception): void
    {
        $deliveryTag = $message->getDeliveryTag();
        $metadata = MessageRetryMetadata::fromAmqpMessage($message);

        Log::error('payment request processing failed', [
            'error' => $exception->getMessage(),
            'routing_key' => $message->getRoutingKey(),
            'retry_count' => $metadata->retryCount,
        ]);

        if (! $this->retryPolicy->isRetryable($exception)) {
            $this->moveToDlq($channel, $message, $exception);
            $channel->basic_ack($deliveryTag);

            return;
        }

        if ($this->retryPolicy->shouldRetry($metadata->retryCount)) {
            $this->retryPublisher->publish($channel, $message, $metadata->retryCount + 1);

            Log::info('payment request scheduled for retry', [
                'routing_key' => $message->getRoutingKey(),
                'retry_count' => $metadata->retryCount + 1,
            ]);

            $channel->basic_ack($deliveryTag);

            return;
        }

        $this->moveToDlq($channel, $message, $exception);
        $channel->basic_ack($deliveryTag);
    }

    private function moveToDlq(AMQPChannel $channel, AMQPMessage $message, Throwable $exception): void
    {
        $this->dlqPublisher->publish($channel, $message, $exception);

        Log::warning('payment request moved to dlq', [
            'routing_key' => $message->getRoutingKey(),
            'failure_reason' => class_basename($exception),
        ]);
    }
}
