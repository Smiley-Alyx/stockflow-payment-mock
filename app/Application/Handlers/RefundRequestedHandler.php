<?php

namespace App\Application\Handlers;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Services\Refund\PaymentRefundService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use Illuminate\Support\Facades\Log;

class RefundRequestedHandler
{
    public function __construct(
        private readonly PaymentMessageMapper $mapper,
        private readonly PaymentRefundService $refundService,
        private readonly PaymentEventPublisher $eventPublisher,
    ) {}

    public function handle(IncomingMessage $message): void
    {
        $request = $this->mapper->toRefundRequest($message);

        Log::info('payment refund requested', [
            'correlation_id' => $message->headers->correlationId,
            'payment_id' => $request->paymentId,
            'idempotency_key' => $request->idempotencyKey,
        ]);

        $result = $this->refundService->refund($request);

        if ($result->idempotentReplay) {
            Log::info('payment refund idempotent replay', [
                'correlation_id' => $message->headers->correlationId,
                'payment_id' => $request->paymentId,
                'idempotency_key' => $request->idempotencyKey,
            ]);
        }

        $this->eventPublisher->publishRefundResult($message, $result);
    }
}
