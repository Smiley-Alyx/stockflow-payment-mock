<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Observability\PaymentMetricsRecorder;
use App\Support\PaymentStructuredLogger;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class PaymentRetryRequeueHandler
{
    public function __construct(
        private readonly RabbitMqConfig $config,
        private readonly PaymentMetricsRecorder $metricsRecorder,
    ) {}

    public function handle(AMQPChannel $channel, AMQPMessage $message): void
    {
        $deliveryTag = $message->getDeliveryTag();
        $metadata = MessageRetryMetadata::fromAmqpMessage($message);

        if ($metadata->originalRoutingKey === null || $metadata->originalRoutingKey === '') {
            Log::warning('retry message missing original routing key, rejecting to dlq', [
                'retry_count' => $metadata->retryCount,
            ]);

            $channel->basic_reject($deliveryTag, false);

            return;
        }

        if ($this->shouldWait($metadata)) {
            $channel->basic_nack($deliveryTag, false, true);

            return;
        }

        $channel->basic_publish(
            $message,
            $this->config->exchange,
            $metadata->originalRoutingKey,
        );

        $this->metricsRecorder->recordRetryRequeued($metadata->originalRoutingKey);

        Log::info('payment request requeued from retry queue', PaymentStructuredLogger::context('payment.request.retry_requeued', [
            'routing_key' => $metadata->originalRoutingKey,
            'retry_count' => $metadata->retryCount,
        ]));

        $channel->basic_ack($deliveryTag);
    }

    private function shouldWait(MessageRetryMetadata $metadata): bool
    {
        if ($metadata->retryAfterEpochMs === null) {
            return false;
        }

        return (int) (microtime(true) * 1000) < $metadata->retryAfterEpochMs;
    }
}
