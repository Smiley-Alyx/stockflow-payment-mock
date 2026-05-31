<?php

namespace App\Application\Handlers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Observability\PaymentMetricsRecorder;
use App\Support\PaymentStructuredLogger;
use Illuminate\Support\Facades\Log;

class AuthorizationRequestedHandler
{
    public function __construct(
        private readonly PaymentMessageMapper $mapper,
        private readonly PaymentAuthorizationService $authorizationService,
        private readonly PaymentEventPublisher $eventPublisher,
        private readonly PaymentMetricsRecorder $metricsRecorder,
    ) {}

    public function handle(IncomingMessage $message): void
    {
        $startedAt = hrtime(true);
        $request = $this->mapper->toAuthorizationRequest($message);

        Log::info('payment authorization requested', PaymentStructuredLogger::context('payment.authorization.requested', [
            'correlation_id' => $message->headers->correlationId,
            'payment_id' => $request->paymentId,
            'idempotency_key' => $request->idempotencyKey,
            'routing_key' => $message->routingKey,
        ]));

        $result = $this->authorizationService->authorize($request);

        if ($result->idempotentReplay) {
            Log::info('payment authorization idempotent replay', PaymentStructuredLogger::context('payment.authorization.idempotent_replay', [
                'correlation_id' => $message->headers->correlationId,
                'payment_id' => $request->paymentId,
                'idempotency_key' => $request->idempotencyKey,
            ]));
        }

        $this->metricsRecorder->recordRequestProcessed(
            operation: 'authorization',
            routingKey: $message->routingKey,
            outcome: $result->approved ? 'approved' : 'declined',
            durationSeconds: (hrtime(true) - $startedAt) / 1_000_000_000,
            idempotentReplay: $result->idempotentReplay,
        );

        $this->eventPublisher->publishAuthorizationResult($message, $result);
    }
}
