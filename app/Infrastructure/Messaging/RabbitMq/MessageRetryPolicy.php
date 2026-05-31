<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\PublishedEventConflictException;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\RetryableMessageException;
use Throwable;

class MessageRetryPolicy
{
    public function __construct(
        private readonly RabbitMqConfig $config,
    ) {}

    public function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof InvalidMessageException) {
            return false;
        }

        if ($exception instanceof PublishedEventConflictException) {
            return false;
        }

        if ($exception instanceof RetryableMessageException) {
            return true;
        }

        return true;
    }

    public function shouldRetry(int $currentRetryCount): bool
    {
        return $currentRetryCount < $this->config->maxRetryAttempts;
    }
}
