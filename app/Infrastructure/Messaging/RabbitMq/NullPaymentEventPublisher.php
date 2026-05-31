<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use Illuminate\Support\Facades\Log;

class NullPaymentEventPublisher implements PaymentEventPublisher
{
    public function publishAuthorizationResult(IncomingMessage $incoming, AuthorizationResult $result): void
    {
        $this->log('authorization', $incoming, $result->approved ? 'approved' : 'declined');
    }

    public function publishCaptureResult(IncomingMessage $incoming, CaptureResult $result): void
    {
        $this->log('capture', $incoming, $result->completed ? 'completed' : 'failed');
    }

    public function publishRefundResult(IncomingMessage $incoming, RefundResult $result): void
    {
        $this->log('refund', $incoming, $result->completed ? 'completed' : 'failed');
    }

    private function log(string $operation, IncomingMessage $incoming, string $outcome): void
    {
        Log::debug('payment event publish deferred until RabbitMQ publisher is enabled', [
            'operation' => $operation,
            'outcome' => $outcome,
            'routing_key' => $incoming->routingKey,
            'correlation_id' => $incoming->headers->correlationId,
            'message_id' => $incoming->headers->messageId,
        ]);
    }
}
