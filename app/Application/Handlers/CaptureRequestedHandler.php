<?php

namespace App\Application\Handlers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use Illuminate\Support\Facades\Log;

class CaptureRequestedHandler
{
    public function __construct(
        private readonly PaymentMessageMapper $mapper,
        private readonly PaymentCaptureService $captureService,
        private readonly PaymentEventPublisher $eventPublisher,
    ) {}

    public function handle(IncomingMessage $message): void
    {
        $request = $this->mapper->toCaptureRequest($message);

        Log::info('payment capture requested', [
            'correlation_id' => $message->headers->correlationId,
            'payment_id' => $request->paymentId,
            'idempotency_key' => $request->idempotencyKey,
        ]);

        $result = $this->captureService->capture($request);

        $this->eventPublisher->publishCaptureResult($message, $result);
    }
}
