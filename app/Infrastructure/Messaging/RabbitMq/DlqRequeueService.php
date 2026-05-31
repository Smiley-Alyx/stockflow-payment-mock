<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class DlqRequeueService
{
    public function __construct(
        private readonly RabbitMqConfig $config,
        private readonly RabbitMqConnectionFactory $connectionFactory,
    ) {}

    /**
     * @return array{requeued: int, remaining: int}
     */
    public function requeue(int $limit): array
    {
        $connection = $this->connectionFactory->create();
        $channel = $connection->channel();

        try {
            $requeued = 0;

            while ($requeued < $limit) {
                /** @var AMQPMessage|null $message */
                $message = $channel->basic_get($this->config->dlq, false);

                if ($message === null) {
                    break;
                }

                $metadata = MessageRetryMetadata::fromAmqpMessage($message);
                $routingKey = $metadata->originalRoutingKey ?? (string) ($message->getRoutingKey() ?? '');

                if ($routingKey === '') {
                    Log::warning('dlq message skipped because routing key is missing', [
                        'delivery_tag' => $message->getDeliveryTag(),
                    ]);

                    $channel->basic_ack($message->getDeliveryTag());

                    continue;
                }

                $channel->basic_publish(
                    $message,
                    $this->config->exchange,
                    $routingKey,
                );

                $channel->basic_ack($message->getDeliveryTag());
                $requeued++;
            }

            [, $remaining] = $channel->queue_declare($this->config->dlq, true);

            Log::info('dlq messages requeued', [
                'requeued' => $requeued,
                'remaining' => $remaining,
            ]);

            return [
                'requeued' => $requeued,
                'remaining' => $remaining,
            ];
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    /**
     * @return array{messages: int, consumers: int}
     */
    public function stats(): array
    {
        $connection = $this->connectionFactory->create();
        $channel = $connection->channel();

        try {
            [, $messageCount, $consumerCount] = $channel->queue_declare($this->config->dlq, true);

            return [
                'messages' => $messageCount,
                'consumers' => $consumerCount,
            ];
        } finally {
            $channel->close();
            $connection->close();
        }
    }
}
