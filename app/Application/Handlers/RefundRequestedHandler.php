<?php

namespace App\Application\Handlers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Refund\PaymentRefundService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Observability\PaymentMetricsRecorder;
use App\Support\PaymentStructuredLogger;
use Illuminate\Support\Facades\Log;

class RefundRequestedHandler
{
    public function __construct(
        private readonly PaymentMessageMapper $mapper,
        private readonly PaymentRefundService $refundService,
        private readonly PaymentEventPublisher $eventPublisher,
        private readonly PaymentMetricsRecorder $metricsRecorder,
    ) {}

    public function handle(IncomingMessage $message): void
    {
        $startedAt = hrtime(true);
        $request = $this->mapper->toRefundRequest($message);

        Log::info('payment refund requested', PaymentStructuredLogger::context('payment.refund.requested', [
            'correlation_id' => $message->headers->correlationId,
            'payment_id' => $request->paymentId,
            'idempotency_key' => $request->idempotencyKey,
            'routing_key' => $message->routingKey,
        ]));

        $result = $this->refundService->refund($request);

        if ($result->idempotentReplay) {
            Log::info('payment refund idempotent replay', PaymentStructuredLogger::context('payment.refund.idempotent_replay', [
                'correlation_id' => $message->headers->correlationId,
                'payment_id' => $request->paymentId,
                'idempotency_key' => $request->idempotencyKey,
            ]));
        }

        $this->metricsRecorder->recordRequestProcessed(
            operation: 'refund',
            routingKey: $message->routingKey,
            outcome: $result->completed ? 'completed' : 'failed',
            durationSeconds: (hrtime(true) - $startedAt) / 1_000_000_000,
            idempotentReplay: $result->idempotentReplay,
        );

        $this->eventPublisher->publishRefundResult($message, $result);
    }
}
