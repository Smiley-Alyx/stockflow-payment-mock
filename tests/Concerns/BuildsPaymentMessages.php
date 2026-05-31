<?php

namespace Tests\Concerns;

use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;

trait BuildsPaymentMessages
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function authorizationMessage(
        string $paymentId,
        string $idempotencyKey,
        string $messageId = 'msg_auth_req_001',
        string $token = 'tok_approved_visa',
        int $amount = 12_990,
        string $correlationId = 'cor_checkout_001',
    ): IncomingMessage {
        return $this->paymentMessage(
            routingKey: 'payment.authorization.requested.v1',
            messageId: $messageId,
            correlationId: $correlationId,
            idempotencyKey: $idempotencyKey,
            payload: [
                'payment_id' => $paymentId,
                'order_id' => 'ord_'.$paymentId,
                'customer_id' => 'cust_'.$paymentId,
                'amount' => ['value' => $amount, 'currency' => 'EUR'],
                'payment_method' => ['type' => 'card', 'token' => $token],
                'capture_mode' => 'manual',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function captureMessage(
        string $paymentId,
        string $idempotencyKey,
        string $messageId = 'msg_cap_req_001',
        ?int $amount = null,
        string $correlationId = 'cor_checkout_001',
    ): IncomingMessage {
        $payload = ['payment_id' => $paymentId];

        if ($amount !== null) {
            $payload['amount'] = ['value' => $amount, 'currency' => 'EUR'];
        }

        return $this->paymentMessage(
            routingKey: 'payment.capture.requested.v1',
            messageId: $messageId,
            correlationId: $correlationId,
            idempotencyKey: $idempotencyKey,
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function refundMessage(
        string $paymentId,
        string $idempotencyKey,
        string $messageId = 'msg_ref_req_001',
        int $amount = 5_000,
        string $correlationId = 'cor_checkout_001',
    ): IncomingMessage {
        return $this->paymentMessage(
            routingKey: 'payment.refund.requested.v1',
            messageId: $messageId,
            correlationId: $correlationId,
            idempotencyKey: $idempotencyKey,
            payload: [
                'payment_id' => $paymentId,
                'amount' => ['value' => $amount, 'currency' => 'EUR'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paymentMessage(
        string $routingKey,
        string $messageId,
        string $correlationId,
        string $idempotencyKey,
        array $payload,
    ): IncomingMessage {
        return new IncomingMessage(
            routingKey: $routingKey,
            headers: new MessageHeaders(
                messageId: $messageId,
                correlationId: $correlationId,
                causationId: 'msg_parent_001',
                idempotencyKey: $idempotencyKey,
                schemaVersion: 'v1',
                occurredAt: '2026-05-31T10:00:00Z',
                producer: 'stockflow-market',
            ),
            payload: $payload,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
