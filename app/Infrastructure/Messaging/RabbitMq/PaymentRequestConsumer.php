<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class PaymentRequestConsumer
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly RabbitMqConfig $config,
        private readonly RabbitMqConnectionFactory $connectionFactory,
        private readonly RabbitMqTopologyManager $topologyManager,
        private readonly PaymentRequestProcessor $requestProcessor,
        private readonly PaymentRetryRequeueHandler $retryRequeueHandler,
    ) {}

    public function consume(): int
    {
        $this->registerSignalHandlers();

        $connection = $this->connectionFactory->create();
        $channel = $connection->channel();

        try {
            if ($this->config->setupTopology) {
                $this->topologyManager->declare($channel);
            }

            $channel->basic_qos(0, $this->config->prefetchCount, false);

            $channel->basic_consume(
                queue: $this->config->requestsQueue,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: function (AMQPMessage $message) use ($channel): void {
                    $this->requestProcessor->process($channel, $message);
                },
            );

            $channel->basic_consume(
                queue: $this->config->retryQueue,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: function (AMQPMessage $message) use ($channel): void {
                    $this->retryRequeueHandler->handle($channel, $message);
                },
            );

            Log::info('payment request consumer started', [
                'queue' => $this->config->requestsQueue,
                'retry_queue' => $this->config->retryQueue,
                'exchange' => $this->config->exchange,
                'max_retry_attempts' => $this->config->maxRetryAttempts,
            ]);

            while ($channel->is_consuming() && ! $this->shouldStop) {
                try {
                    $channel->wait(null, false, $this->config->consumerTimeoutSeconds);
                } catch (AMQPTimeoutException) {
                    // Poll again so signal handlers can stop an idle consumer.
                }
            }

            Log::info('payment request consumer stopped gracefully');

            return self::SUCCESS;
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    public function requestStop(): void
    {
        $this->shouldStop = true;
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            Log::info('payment request consumer received shutdown signal', [
                'signal' => $signal,
            ]);

            $this->requestStop();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    public const SUCCESS = 0;
}
