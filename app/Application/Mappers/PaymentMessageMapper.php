<?php

namespace App\Application\Mappers;

use App\Domain\Payment\DTO\AuthorizationRequest;
use App\Domain\Payment\DTO\CaptureRequest;
use App\Domain\Payment\DTO\RefundRequest;
use App\Domain\Payment\Enums\CaptureMode;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;

class PaymentMessageMapper
{
    public function toAuthorizationRequest(IncomingMessage $message): AuthorizationRequest
    {
        $payload = $message->payload;

        foreach (['payment_id', 'order_id', 'customer_id', 'amount', 'payment_method', 'capture_mode'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidMessageException(sprintf('Missing authorization field: %s', $field));
            }
        }

        $amount = $payload['amount'];
        $paymentMethod = $payload['payment_method'];

        if (! is_array($amount) || ! isset($amount['value'], $amount['currency'])) {
            throw new InvalidMessageException('Invalid authorization amount.');
        }

        if (! is_array($paymentMethod) || ! isset($paymentMethod['type'], $paymentMethod['token'])) {
            throw new InvalidMessageException('Invalid authorization payment_method.');
        }

        $captureMode = CaptureMode::tryFrom((string) $payload['capture_mode']);

        if ($captureMode === null) {
            throw new InvalidMessageException('Invalid capture_mode.');
        }

        return new AuthorizationRequest(
            paymentId: (string) $payload['payment_id'],
            orderId: (string) $payload['order_id'],
            customerId: (string) $payload['customer_id'],
            amountValue: (int) $amount['value'],
            amountCurrency: (string) $amount['currency'],
            paymentMethodType: (string) $paymentMethod['type'],
            paymentMethodToken: (string) $paymentMethod['token'],
            captureMode: $captureMode,
            idempotencyKey: $message->headers->idempotencyKey,
            messageId: $message->headers->messageId,
            correlationId: $message->headers->correlationId,
            metadata: isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null,
        );
    }

    public function toCaptureRequest(IncomingMessage $message): CaptureRequest
    {
        $payload = $message->payload;

        if (! isset($payload['payment_id'])) {
            throw new InvalidMessageException('Missing capture field: payment_id');
        }

        $amountValue = null;
        $amountCurrency = null;

        if (isset($payload['amount'])) {
            if (! is_array($payload['amount']) || ! isset($payload['amount']['value'], $payload['amount']['currency'])) {
                throw new InvalidMessageException('Invalid capture amount.');
            }

            $amountValue = (int) $payload['amount']['value'];
            $amountCurrency = (string) $payload['amount']['currency'];
        }

        return new CaptureRequest(
            paymentId: (string) $payload['payment_id'],
            idempotencyKey: $message->headers->idempotencyKey,
            amountValue: $amountValue,
            amountCurrency: $amountCurrency,
            messageId: $message->headers->messageId,
            correlationId: $message->headers->correlationId,
            metadata: isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null,
        );
    }

    public function toRefundRequest(IncomingMessage $message): RefundRequest
    {
        $payload = $message->payload;

        if (! isset($payload['payment_id'])) {
            throw new InvalidMessageException('Missing refund field: payment_id');
        }

        $amountValue = null;
        $amountCurrency = null;

        if (isset($payload['amount'])) {
            if (! is_array($payload['amount']) || ! isset($payload['amount']['value'], $payload['amount']['currency'])) {
                throw new InvalidMessageException('Invalid refund amount.');
            }

            $amountValue = (int) $payload['amount']['value'];
            $amountCurrency = (string) $payload['amount']['currency'];
        }

        return new RefundRequest(
            paymentId: (string) $payload['payment_id'],
            idempotencyKey: $message->headers->idempotencyKey,
            amountValue: $amountValue,
            amountCurrency: $amountCurrency,
            messageId: $message->headers->messageId,
            correlationId: $message->headers->correlationId,
            metadata: isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null,
        );
    }
}
