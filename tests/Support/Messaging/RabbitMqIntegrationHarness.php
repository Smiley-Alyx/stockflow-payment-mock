<?php

namespace Tests\Support\Messaging;

use App\Infrastructure\Messaging\RabbitMq\PaymentRequestProcessor;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConnectionFactory;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqTopologyManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMqIntegrationHarness
{
    public const RESULTS_QUEUE = 'stockflow.payment.integration.results';

    /** @var list<string> */
    private const RESULT_ROUTING_KEYS = [
        'payment.authorization.approved.v1',
        'payment.authorization.declined.v1',
        'payment.capture.completed.v1',
        'payment.capture.failed.v1',
        'payment.refund.completed.v1',
        'payment.refund.failed.v1',
    ];

    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly RabbitMqConfig $config,
        private readonly RabbitMqConnectionFactory $connectionFactory,
        private readonly RabbitMqTopologyManager $topologyManager,
        private readonly PaymentRequestProcessor $requestProcessor,
    ) {}

    public static function isBrokerAvailable(): bool
    {
        $host = (string) env('RABBITMQ_HOST', '127.0.0.1');
        $port = (int) env('RABBITMQ_PORT', 5672);

        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        try {
            $config = RabbitMqConfig::fromConfig();
            $connection = (new RabbitMqConnectionFactory($config))->create();
            $connection->close();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setUp(): void
    {
        $this->channel = $this->connection()->channel();
        $this->topologyManager->declare($this->channel);
        $this->declareResultsQueue();
        $this->purgeQueues();
    }

    public function tearDown(): void
    {
        if ($this->channel !== null) {
            $this->channel->close();
            $this->channel = null;
        }

        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function publishRequest(string $routingKey, array $headers, array $payload): void
    {
        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($headers),
            ],
        );

        $this->channel()->basic_publish(
            $message,
            $this->config->exchange,
            $routingKey,
        );
    }

    public function processNextRequest(): bool
    {
        /** @var AMQPMessage|null $message */
        $message = $this->channel()->basic_get($this->config->requestsQueue, false);

        if ($message === null) {
            return false;
        }

        $this->requestProcessor->process($this->channel(), $message);

        return true;
    }

    public function processAllPendingRequests(int $maxMessages = 10): int
    {
        $processed = 0;

        while ($processed < $maxMessages && $this->processNextRequest()) {
            $processed++;
        }

        return $processed;
    }

    /**
     * @param  callable(array{routing_key: string, headers: array<string, string>, payload: array<string, mixed>}): bool|null  $matcher
     * @return array{routing_key: string, headers: array<string, string>, payload: array<string, mixed>}|null
     */
    public function waitForResultEvent(?callable $matcher = null, int $timeoutMs = 5000): ?array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            /** @var AMQPMessage|null $message */
            $message = $this->channel()->basic_get(self::RESULTS_QUEUE, false);

            if ($message === null) {
                usleep(50_000);

                continue;
            }

            $this->channel()->basic_ack($message->getDeliveryTag());

            $event = $this->decodeMessage($message);

            if ($matcher === null || $matcher($event)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return list<array{routing_key: string, headers: array<string, string>, payload: array<string, mixed>}>
     */
    public function drainResultEvents(int $maxMessages = 20): array
    {
        $events = [];

        for ($i = 0; $i < $maxMessages; $i++) {
            /** @var AMQPMessage|null $message */
            $message = $this->channel()->basic_get(self::RESULTS_QUEUE, false);

            if ($message === null) {
                break;
            }

            $this->channel()->basic_ack($message->getDeliveryTag());
            $events[] = $this->decodeMessage($message);
        }

        return $events;
    }

    private function connection(): AbstractConnection
    {
        if ($this->connection === null) {
            $this->connection = $this->connectionFactory->create();
        }

        return $this->connection;
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            throw new \RuntimeException('RabbitMQ integration harness is not set up.');
        }

        return $this->channel;
    }

    private function declareResultsQueue(): void
    {
        $this->channel()->queue_declare(
            self::RESULTS_QUEUE,
            false,
            true,
            false,
            false,
        );

        foreach (self::RESULT_ROUTING_KEYS as $routingKey) {
            $this->channel()->queue_bind(
                self::RESULTS_QUEUE,
                $this->config->exchange,
                $routingKey,
            );
        }
    }

    private function purgeQueues(): void
    {
        foreach ([
            $this->config->requestsQueue,
            $this->config->retryQueue,
            $this->config->dlq,
            self::RESULTS_QUEUE,
        ] as $queue) {
            $this->channel()->queue_purge($queue);
        }
    }

    /**
     * @return array{routing_key: string, headers: array<string, string>, payload: array<string, mixed>}
     */
    private function decodeMessage(AMQPMessage $message): array
    {
        $properties = $message->get_properties();
        $applicationHeaders = $properties['application_headers'] ?? null;
        $headers = [];

        if ($applicationHeaders instanceof AMQPTable) {
            $headers = array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value),
                $applicationHeaders->getNativeData(),
            );
        }

        $body = (string) $message->getBody();
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new \RuntimeException('Expected JSON object payload in result event.');
        }

        return [
            'routing_key' => (string) ($message->getRoutingKey() ?? ''),
            'headers' => $headers,
            'payload' => $payload,
        ];
    }
}
