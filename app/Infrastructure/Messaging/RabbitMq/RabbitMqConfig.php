<?php

namespace App\Infrastructure\Messaging\RabbitMq;

readonly class RabbitMqConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $password,
        public string $vhost,
        public string $exchange,
        public string $deadLetterExchange,
        public string $requestsQueue,
        public string $retryQueue,
        public string $dlq,
        public int $prefetchCount,
        public int $consumerTimeoutSeconds,
        public bool $setupTopology,
        public int $maxRetryAttempts,
        public int $retryDelayMs,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('payment_mock.rabbitmq');

        return new self(
            host: (string) $config['host'],
            port: (int) $config['port'],
            user: (string) $config['user'],
            password: (string) $config['password'],
            vhost: (string) $config['vhost'],
            exchange: (string) $config['exchange'],
            deadLetterExchange: (string) $config['dead_letter_exchange'],
            requestsQueue: (string) $config['requests_queue'],
            retryQueue: (string) $config['retry_queue'],
            dlq: (string) $config['dlq'],
            prefetchCount: (int) $config['prefetch_count'],
            consumerTimeoutSeconds: (int) $config['consumer_timeout_seconds'],
            setupTopology: filter_var($config['setup_topology'], FILTER_VALIDATE_BOOL),
            maxRetryAttempts: (int) $config['max_retry_attempts'],
            retryDelayMs: (int) $config['retry_delay_ms'],
        );
    }

    /**
     * @return list<string>
     */
    public function incomingRoutingKeys(): array
    {
        return [
            'payment.authorization.requested.v1',
            'payment.capture.requested.v1',
            'payment.refund.requested.v1',
        ];
    }
}
