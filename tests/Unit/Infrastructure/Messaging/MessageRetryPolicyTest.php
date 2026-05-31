<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\PublishedEventConflictException;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\RetryableMessageException;
use App\Infrastructure\Messaging\RabbitMq\MessageRetryPolicy;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqConfig;
use RuntimeException;
use Tests\TestCase;

class MessageRetryPolicyTest extends TestCase
{
    public function test_invalid_messages_are_not_retryable(): void
    {
        $policy = $this->policy(maxRetryAttempts: 3);

        $this->assertFalse($policy->isRetryable(new InvalidMessageException('bad headers')));
    }

    public function test_published_event_conflicts_are_not_retryable(): void
    {
        $policy = $this->policy(maxRetryAttempts: 3);

        $this->assertFalse($policy->isRetryable(
            PublishedEventConflictException::forOperation('payment.authorization', 'pay_1', 'idem_1'),
        ));
    }

    public function test_generic_failures_are_retryable(): void
    {
        $policy = $this->policy(maxRetryAttempts: 3);

        $this->assertTrue($policy->isRetryable(new RuntimeException('db unavailable')));
        $this->assertTrue($policy->isRetryable(new RetryableMessageException('transient')));
    }

    public function test_should_retry_until_max_attempts(): void
    {
        $policy = $this->policy(maxRetryAttempts: 3);

        $this->assertTrue($policy->shouldRetry(0));
        $this->assertTrue($policy->shouldRetry(2));
        $this->assertFalse($policy->shouldRetry(3));
    }

    private function policy(int $maxRetryAttempts): MessageRetryPolicy
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
