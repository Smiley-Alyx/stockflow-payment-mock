<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use Illuminate\Support\Str;

class OutgoingMessageHeadersFactory
{
    public function forResponse(IncomingMessage $incoming): MessageHeaders
    {
        return new MessageHeaders(
            messageId: $this->generateMessageId(),
            correlationId: $incoming->headers->correlationId,
            causationId: $incoming->headers->messageId,
            idempotencyKey: $incoming->headers->idempotencyKey,
            schemaVersion: 'v1',
            occurredAt: now()->utc()->format('Y-m-d\TH:i:s\Z'),
            producer: (string) config('payment_mock.service_name'),
        );
    }

    public function generateMessageId(): string
    {
        return 'msg_'.strtolower((string) Str::ulid());
    }
}
