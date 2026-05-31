<?php

namespace App\Application\Handlers;

use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;

class PaymentMessageDispatcher
{
    public function __construct(
        private readonly AuthorizationRequestedHandler $authorizationRequestedHandler,
        private readonly CaptureRequestedHandler $captureRequestedHandler,
        private readonly RefundRequestedHandler $refundRequestedHandler,
    ) {}

    public function dispatch(IncomingMessage $message): void
    {
        match ($message->routingKey) {
            'payment.authorization.requested.v1' => $this->authorizationRequestedHandler->handle($message),
            'payment.capture.requested.v1' => $this->captureRequestedHandler->handle($message),
            'payment.refund.requested.v1' => $this->refundRequestedHandler->handle($message),
            default => throw new InvalidMessageException(sprintf('Unsupported routing key: %s', $message->routingKey)),
        };
    }
}
