<?php

namespace App\Application\Mappers;

use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use App\Infrastructure\Messaging\RabbitMq\PublishedPaymentEvent;

class PaymentEventPayloadMapper
{
    public function __construct(
        private readonly OutgoingMessageHeadersFactory $headersFactory,
    ) {}

    public function authorizationResult(IncomingMessage $incoming, AuthorizationResult $result): PublishedPaymentEvent
    {
        $headers = $this->headersFactory->forResponse($incoming);
        $payment = $result->payment;
        $authorization = $result->authorization;

        if ($authorization === null) {
            throw new InvalidMessageException('Authorization result is missing authorization record.');
        }

        if ($result->approved) {
            return new PublishedPaymentEvent(
                routingKey: 'payment.authorization.approved.v1',
                headers: $headers,
                payload: [
                    'payment_id' => $payment->payment_id,
                    'authorization_id' => $authorization->authorization_id,
                    'order_id' => $payment->order_id,
                    'status' => 'approved',
                    'amount' => [
                        'value' => $authorization->amount_value,
                        'currency' => $authorization->amount_currency,
                    ],
                    'authorized_at' => $authorization->authorized_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ],
            );
        }

        return new PublishedPaymentEvent(
            routingKey: 'payment.authorization.declined.v1',
            headers: $headers,
            payload: [
                'payment_id' => $payment->payment_id,
                'order_id' => $payment->order_id,
                'status' => 'declined',
                'reason_code' => $authorization->reason_code ?? $result->declineReason?->value,
                'reason_message' => $authorization->reason_message ?? $result->declineReason?->message(),
            ],
        );
    }

    public function captureResult(IncomingMessage $incoming, CaptureResult $result): PublishedPaymentEvent
    {
        $headers = $this->headersFactory->forResponse($incoming);
        $payment = $result->payment;
        $capture = $result->capture;

        if ($result->completed) {
            if ($capture === null) {
                throw new InvalidMessageException('Capture result is missing capture record.');
            }

            return new PublishedPaymentEvent(
                routingKey: 'payment.capture.completed.v1',
                headers: $headers,
                payload: [
                    'payment_id' => $payment->payment_id,
                    'capture_id' => $capture->capture_id,
                    'authorization_id' => $capture->authorization_id,
                    'status' => 'completed',
                    'amount' => [
                        'value' => $capture->amount_value,
                        'currency' => $capture->amount_currency,
                    ],
                    'captured_at' => $capture->captured_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ],
            );
        }

        return new PublishedPaymentEvent(
            routingKey: 'payment.capture.failed.v1',
            headers: $headers,
            payload: array_filter([
                'payment_id' => $payment->payment_id,
                'capture_id' => $capture?->capture_id,
                'status' => 'failed',
                'reason_code' => $capture?->reason_code ?? $result->failureReason?->value,
                'reason_message' => $capture?->reason_message ?? $result->failureReason?->message(),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function refundResult(IncomingMessage $incoming, RefundResult $result): PublishedPaymentEvent
    {
        $headers = $this->headersFactory->forResponse($incoming);
        $payment = $result->payment;
        $refund = $result->refund;

        if ($result->completed) {
            if ($refund === null) {
                throw new InvalidMessageException('Refund result is missing refund record.');
            }

            return new PublishedPaymentEvent(
                routingKey: 'payment.refund.completed.v1',
                headers: $headers,
                payload: [
                    'payment_id' => $payment->payment_id,
                    'refund_id' => $refund->refund_id,
                    'capture_id' => $refund->capture_id,
                    'status' => 'completed',
                    'amount' => [
                        'value' => $refund->amount_value,
                        'currency' => $refund->amount_currency,
                    ],
                    'refunded_at' => $refund->refunded_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ],
            );
        }

        return new PublishedPaymentEvent(
            routingKey: 'payment.refund.failed.v1',
            headers: $headers,
            payload: array_filter([
                'payment_id' => $payment->payment_id,
                'refund_id' => $refund?->refund_id,
                'status' => 'failed',
                'reason_code' => $refund?->reason_code ?? $result->attempt->reason_code ?? $result->failureReason?->value,
                'reason_message' => $refund?->reason_message ?? $result->attempt->reason_message ?? $result->failureReason?->message(),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
