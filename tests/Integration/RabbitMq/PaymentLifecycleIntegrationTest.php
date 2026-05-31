<?php

namespace Tests\Integration\RabbitMq;

class PaymentLifecycleIntegrationTest extends RabbitMqIntegrationTestCase
{
    public function test_authorization_capture_and_refund_flow_over_rabbitmq(): void
    {
        $paymentId = $this->uniquePaymentId('pay_int_lifecycle');
        $correlationId = 'cor_int_lifecycle';

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.authorization.requested.v1',
            headers: $this->requestHeaders('life_auth', 'idem_int_life_auth', $correlationId),
            payload: $this->authorizationPayload($paymentId),
        );
        $this->assertTrue($this->rabbitMq->processNextRequest());

        $authEvent = $this->rabbitMq->waitForResultEvent(
            fn (array $event): bool => ($event['payload']['payment_id'] ?? null) === $paymentId
                && ($event['payload']['status'] ?? null) === 'approved',
        );
        $this->assertNotNull($authEvent);

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.capture.requested.v1',
            headers: $this->requestHeaders('life_cap', 'idem_int_life_cap', $correlationId),
            payload: [
                'payment_id' => $paymentId,
                'amount' => ['value' => 12_990, 'currency' => 'EUR'],
            ],
        );
        $this->assertTrue($this->rabbitMq->processNextRequest());

        $captureEvent = $this->rabbitMq->waitForResultEvent(
            fn (array $event): bool => ($event['payload']['payment_id'] ?? null) === $paymentId
                && ($event['payload']['status'] ?? null) === 'completed',
        );
        $this->assertNotNull($captureEvent);

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.refund.requested.v1',
            headers: $this->requestHeaders('life_ref', 'idem_int_life_ref', $correlationId),
            payload: [
                'payment_id' => $paymentId,
                'amount' => ['value' => 5_000, 'currency' => 'EUR'],
            ],
        );
        $this->assertTrue($this->rabbitMq->processNextRequest());

        $refundEvent = $this->rabbitMq->waitForResultEvent(
            fn (array $event): bool => ($event['payload']['payment_id'] ?? null) === $paymentId
                && ($event['payload']['status'] ?? null) === 'completed',
        );
        $this->assertNotNull($refundEvent);
        $this->assertSame($correlationId, $refundEvent['headers']['correlation_id']);
    }
}
