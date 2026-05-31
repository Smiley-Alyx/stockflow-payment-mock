<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Application\Mappers\PaymentEventPayloadMapper;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Domain\Payment\Models\PublishedEventRecord;
use App\Domain\Payment\Services\Debug\ProviderDegradationSimulator;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\PublishedEventConflictException;
use Illuminate\Support\Facades\Log;

class IdempotentPaymentEventPublisher implements PaymentEventPublisher
{
    public function __construct(
        private readonly PaymentEventPayloadMapper $eventPayloadMapper,
        private readonly RabbitMqMessagePublisher $messagePublisher,
        private readonly PublishedEventStore $publishedEventStore,
        private readonly PaymentIdempotencyService $idempotencyService,
        private readonly ProviderDegradationSimulator $degradationSimulator,
    ) {}

    public function publishAuthorizationResult(IncomingMessage $incoming, AuthorizationResult $result): void
    {
        $this->publishIdempotently(
            operation: PublishedEventRecord::OPERATION_AUTHORIZATION,
            incoming: $incoming,
            paymentId: $result->payment->payment_id,
            responseFingerprint: $this->idempotencyService->authorizationFingerprint($result),
            idempotentReplay: $result->idempotentReplay,
            eventFactory: fn (): PublishedPaymentEvent => $this->eventPayloadMapper->authorizationResult($incoming, $result),
        );
    }

    public function publishCaptureResult(IncomingMessage $incoming, CaptureResult $result): void
    {
        $this->publishIdempotently(
            operation: PublishedEventRecord::OPERATION_CAPTURE,
            incoming: $incoming,
            paymentId: $result->payment->payment_id,
            responseFingerprint: $this->idempotencyService->captureFingerprint($result),
            idempotentReplay: $result->idempotentReplay,
            eventFactory: fn (): PublishedPaymentEvent => $this->eventPayloadMapper->captureResult($incoming, $result),
        );
    }

    public function publishRefundResult(IncomingMessage $incoming, RefundResult $result): void
    {
        $this->publishIdempotently(
            operation: PublishedEventRecord::OPERATION_REFUND,
            incoming: $incoming,
            paymentId: $result->payment->payment_id,
            responseFingerprint: $this->idempotencyService->refundFingerprint($result),
            idempotentReplay: $result->idempotentReplay,
            eventFactory: fn (): PublishedPaymentEvent => $this->eventPayloadMapper->refundResult($incoming, $result),
        );
    }

    /**
     * @param  callable(): PublishedPaymentEvent  $eventFactory
     */
    private function publishIdempotently(
        string $operation,
        IncomingMessage $incoming,
        string $paymentId,
        string $responseFingerprint,
        bool $idempotentReplay,
        callable $eventFactory,
    ): void {
        $idempotencyKey = $incoming->headers->idempotencyKey;
        $existing = $this->publishedEventStore->find($operation, $paymentId, $idempotencyKey);

        if ($existing !== null) {
            $this->assertMatchingFingerprint($existing, $responseFingerprint, $operation, $paymentId, $idempotencyKey);
            $this->publishStoredEvent($existing, $idempotentReplay);

            return;
        }

        $event = $eventFactory();
        $this->publishEvent($event, false);

        $stored = $this->publishedEventStore->store(
            $operation,
            $paymentId,
            $idempotencyKey,
            $event,
            $responseFingerprint,
        );

        if ($stored->response_fingerprint !== $responseFingerprint) {
            $this->assertMatchingFingerprint($stored, $responseFingerprint, $operation, $paymentId, $idempotencyKey);
        }
    }

    private function publishStoredEvent(PublishedEventRecord $record, bool $idempotentReplay): void
    {
        $event = $this->publishedEventStore->toPublishedEvent($record);
        $this->publishEvent($event, true, $idempotentReplay);
    }

    private function publishEvent(
        PublishedPaymentEvent $event,
        bool $storedReplay,
        bool $domainIdempotentReplay = false,
    ): void {
        $this->degradationSimulator->assertPublishAllowed();
        $this->messagePublisher->publish($event);

        Log::info('payment event published', [
            'routing_key' => $event->routingKey,
            'correlation_id' => $event->headers->correlationId,
            'causation_id' => $event->headers->causationId,
            'message_id' => $event->headers->messageId,
            'idempotency_key' => $event->headers->idempotencyKey,
            'idempotent_event_replay' => $storedReplay,
            'domain_idempotent_replay' => $domainIdempotentReplay,
        ]);

        if (! $storedReplay && $this->degradationSimulator->shouldDuplicatePublishedResponse()) {
            $this->messagePublisher->publish($event);

            Log::info('payment event duplicate published', [
                'routing_key' => $event->routingKey,
                'message_id' => $event->headers->messageId,
                'idempotency_key' => $event->headers->idempotencyKey,
            ]);
        }
    }

    private function assertMatchingFingerprint(
        PublishedEventRecord $record,
        string $responseFingerprint,
        string $operation,
        string $paymentId,
        string $idempotencyKey,
    ): void {
        if ($record->response_fingerprint !== $responseFingerprint) {
            throw PublishedEventConflictException::forOperation($operation, $paymentId, $idempotencyKey);
        }
    }
}
