<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Domain\Payment\Models\PublishedEventRecord;
use Illuminate\Database\QueryException;

class PublishedEventStore
{
    public function find(string $operation, string $paymentId, string $idempotencyKey): ?PublishedEventRecord
    {
        return PublishedEventRecord::query()
            ->where('operation', $operation)
            ->where('payment_id', $paymentId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function store(
        string $operation,
        string $paymentId,
        string $idempotencyKey,
        PublishedPaymentEvent $event,
        string $responseFingerprint,
    ): PublishedEventRecord {
        try {
            return PublishedEventRecord::query()->create([
                'operation' => $operation,
                'payment_id' => $paymentId,
                'idempotency_key' => $idempotencyKey,
                'routing_key' => $event->routingKey,
                'message_id' => $event->headers->messageId,
                'correlation_id' => $event->headers->correlationId,
                'causation_id' => $event->headers->causationId,
                'schema_version' => $event->headers->schemaVersion,
                'occurred_at' => $event->headers->occurredAt,
                'producer' => $event->headers->producer,
                'payload' => $event->payload,
                'response_fingerprint' => $responseFingerprint,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return PublishedEventRecord::query()
                ->where('operation', $operation)
                ->where('payment_id', $paymentId)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    public function toPublishedEvent(PublishedEventRecord $record): PublishedPaymentEvent
    {
        return new PublishedPaymentEvent(
            routingKey: $record->routing_key,
            headers: new MessageHeaders(
                messageId: $record->message_id,
                correlationId: $record->correlation_id,
                causationId: $record->causation_id,
                idempotencyKey: $record->idempotency_key,
                schemaVersion: $record->schema_version,
                occurredAt: $record->occurred_at,
                producer: $record->producer,
            ),
            payload: $record->payload,
        );
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'published_event_records')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
