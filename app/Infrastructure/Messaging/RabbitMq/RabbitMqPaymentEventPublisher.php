<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Application\Mappers\PaymentEventPayloadMapper;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use Illuminate\Support\Facades\Log;

class RabbitMqPaymentEventPublisher implements PaymentEventPublisher
{
    public function __construct(
        private readonly PaymentEventPayloadMapper $eventPayloadMapper,
        private readonly RabbitMqMessagePublisher $messagePublisher,
    ) {}

    public function publishAuthorizationResult(IncomingMessage $incoming, AuthorizationResult $result): void
    {
        $this->publish($this->eventPayloadMapper->authorizationResult($incoming, $result));
    }

    public function publishCaptureResult(IncomingMessage $incoming, CaptureResult $result): void
    {
        $this->publish($this->eventPayloadMapper->captureResult($incoming, $result));
    }

    public function publishRefundResult(IncomingMessage $incoming, RefundResult $result): void
    {
        $this->publish($this->eventPayloadMapper->refundResult($incoming, $result));
    }

    private function publish(PublishedPaymentEvent $event): void
    {
        $this->messagePublisher->publish($event);

        Log::info('payment event published', [
            'routing_key' => $event->routingKey,
            'correlation_id' => $event->headers->correlationId,
            'causation_id' => $event->headers->causationId,
            'message_id' => $event->headers->messageId,
            'idempotency_key' => $event->headers->idempotencyKey,
        ]);
    }
}
