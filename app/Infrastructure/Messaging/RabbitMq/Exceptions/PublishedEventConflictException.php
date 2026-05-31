<?php

namespace App\Infrastructure\Messaging\RabbitMq\Exceptions;

use RuntimeException;

class PublishedEventConflictException extends RuntimeException
{
    public static function forOperation(string $operation, string $paymentId, string $idempotencyKey): self
    {
        return new self(sprintf(
            'Published event conflict for operation [%s], payment [%s], idempotency key [%s].',
            $operation,
            $paymentId,
            $idempotencyKey,
        ));
    }
}
