<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\MessageRetryMetadata;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

class MessageRetryMetadataTest extends TestCase
{
    public function test_reads_retry_headers_from_amqp_message(): void
    {
        $message = new AMQPMessage('{}', [
            'application_headers' => new AMQPTable([
                'x-retry-count' => '2',
                'x-original-routing-key' => 'payment.capture.requested.v1',
                'x-retry-after' => '1717158901000',
            ]),
        ]);

        $metadata = MessageRetryMetadata::fromAmqpMessage($message);

        $this->assertSame(2, $metadata->retryCount);
        $this->assertSame('payment.capture.requested.v1', $metadata->originalRoutingKey);
        $this->assertSame(1717158901000, $metadata->retryAfterEpochMs);
    }

    public function test_defaults_retry_count_to_zero(): void
    {
        $metadata = MessageRetryMetadata::fromAmqpMessage(new AMQPMessage('{}'));

        $this->assertSame(0, $metadata->retryCount);
        $this->assertNull($metadata->originalRoutingKey);
        $this->assertNull($metadata->retryAfterEpochMs);
    }
}
