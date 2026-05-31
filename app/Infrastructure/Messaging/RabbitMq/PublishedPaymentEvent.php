<?php

namespace App\Infrastructure\Messaging\RabbitMq;

readonly class PublishedPaymentEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $routingKey,
        public MessageHeaders $headers,
        public array $payload,
    ) {}
}
