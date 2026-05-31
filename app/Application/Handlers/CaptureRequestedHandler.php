<?php

namespace App\Application\Handlers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Observability\PaymentMetricsRecorder;
use App\Support\PaymentStructuredLogger;
use Illuminate\Support\Facades\Log;

class CaptureRequestedHandler
{
    public function __construct(
        private readonly PaymentMessageMapper $mapper,
        private readonly PaymentCaptureService $captureService,
        private readonly PaymentEventPublisher $eventPublisher,
        private readonly PaymentMetricsRecorder $metricsRecorder,
    ) {}

    public function handle(IncomingMessage $message): void
    {
        $startedAt = hrtime(true);
        $request = $this->mapper->toCaptureRequest($message);

        Log::info('payment capture requested', PaymentStructuredLogger::context('payment.capture.requested', [
            'correlation_id' => $message->headers->correlationId,
            'payment_id' => $request->paymentId,
            'idempotency_key' => $request->idempotencyKey,
            'routing_key' => $message->routingKey,
        ]));

        $result = $this->captureService->capture($request);

        if ($result->idempotentReplay) {
            Log::info('payment capture idempotent replay', PaymentStructuredLogger::context('payment.capture.idempotent_replay', [
                'correlation_id' => $message->headers->correlationId,
                'payment_id' => $request->paymentId,
                'idempotency_key' => $request->idempotencyKey,
            ]));
        }

        $this->metricsRecorder->recordRequestProcessed(
            operation: 'capture',
            routingKey: $message->routingKey,
            outcome: $result->completed ? 'completed' : 'failed',
            durationSeconds: (hrtime(true) - $startedAt) / 1_000_000_000,
            idempotentReplay: $result->idempotentReplay,
        );

        $this->eventPublisher->publishCaptureResult($message, $result);
    }
}
