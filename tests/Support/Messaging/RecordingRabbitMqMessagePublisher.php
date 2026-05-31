<?php

namespace Tests\Support\Messaging;

use App\Infrastructure\Messaging\RabbitMq\PublishedPaymentEvent;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqMessagePublisher;

class RecordingRabbitMqMessagePublisher extends RabbitMqMessagePublisher
{
    /** @var list<PublishedPaymentEvent> */
    public array $published = [];

    public function __construct()
    {
        // Skip parent dependencies; this test double only records events.
    }

    public function publish(PublishedPaymentEvent $event): void
    {
        $this->published[] = $event;
    }

    public function close(): void
    {
        // No-op for tests.
    }
}
