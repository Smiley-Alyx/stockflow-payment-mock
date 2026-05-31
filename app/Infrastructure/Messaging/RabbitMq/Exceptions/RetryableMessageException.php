<?php

namespace App\Infrastructure\Messaging\RabbitMq\Exceptions;

use RuntimeException;

class RetryableMessageException extends RuntimeException
{
}
