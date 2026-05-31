<?php

namespace App\Application\Handlers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use Illuminate\Support\Facades\Log;

class AuthorizationRequestedHandler
{
    public function __construct(
        private readonly PaymentMessageMapper $mapper,
        private readonly PaymentAuthorizationService $authorizationService,
        private readonly PaymentEventPublisher $eventPublisher,
    ) {}

    public function handle(IncomingMessage $message): void
    {
        $request = $this->mapper->toAuthorizationRequest($message);

        Log::info('payment authorization requested', [
            'correlation_id' => $message->headers->correlationId,
            'payment_id' => $request->paymentId,
            'idempotency_key' => $request->idempotencyKey,
        ]);

        $result = $this->authorizationService->authorize($request);

        $this->eventPublisher->publishAuthorizationResult($message, $result);
    }
}
