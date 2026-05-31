<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Application\Handlers\PaymentMessageDispatcher;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class PaymentRequestConsumer
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly RabbitMqConfig $config,
        private readonly RabbitMqConnectionFactory $connectionFactory,
        private readonly RabbitMqTopologyManager $topologyManager,
        private readonly MessageHeaderValidator $headerValidator,
        private readonly PaymentMessageDispatcher $dispatcher,
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
                callback: fn (AMQPMessage $message): void => $this->handleMessage($channel, $message),
            );

            Log::info('payment request consumer started', [
                'queue' => $this->config->requestsQueue,
                'exchange' => $this->config->exchange,
            ]);

            while ($channel->is_consuming() && ! $this->shouldStop) {
                $channel->wait(null, false, $this->config->consumerTimeoutSeconds);
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

    private function handleMessage(AMQPChannel $channel, AMQPMessage $message): void
    {
        $deliveryTag = $message->getDeliveryTag();

        try {
            $incoming = IncomingMessage::fromAmqpMessage($message, $this->headerValidator);

            Log::withContext([
                'correlation_id' => $incoming->headers->correlationId,
                'message_id' => $incoming->headers->messageId,
            ]);

            $this->dispatcher->dispatch($incoming);

            $channel->basic_ack($deliveryTag);
        } catch (InvalidMessageException $exception) {
            Log::warning('invalid payment request message rejected', [
                'error' => $exception->getMessage(),
                'routing_key' => $message->getRoutingKey(),
            ]);

            $channel->basic_reject($deliveryTag, false);
        } catch (Throwable $exception) {
            Log::error('payment request processing failed', [
                'error' => $exception->getMessage(),
                'routing_key' => $message->getRoutingKey(),
            ]);

            $channel->basic_nack($deliveryTag, false, false);
        } finally {
            Log::withoutContext();
        }
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
