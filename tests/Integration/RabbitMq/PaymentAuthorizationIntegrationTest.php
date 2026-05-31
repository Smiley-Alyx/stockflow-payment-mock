<?php

namespace Tests\Integration\RabbitMq;

use App\Domain\Payment\Models\Payment;

class PaymentAuthorizationIntegrationTest extends RabbitMqIntegrationTestCase
{
    public function test_authorization_request_publishes_approved_event(): void
    {
        $paymentId = $this->uniquePaymentId('pay_int_auth_ok');
        $correlationId = 'cor_int_auth_ok';

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.authorization.requested.v1',
            headers: $this->requestHeaders('auth_ok', 'idem_int_auth_ok', $correlationId),
            payload: $this->authorizationPayload($paymentId),
        );

        $this->assertTrue($this->rabbitMq->processNextRequest());

        $event = $this->rabbitMq->waitForResultEvent(
            fn (array $event): bool => ($event['payload']['payment_id'] ?? null) === $paymentId,
        );

        $this->assertNotNull($event);
        $this->assertSame('approved', $event['payload']['status']);
        $this->assertSame($correlationId, $event['headers']['correlation_id']);
        $this->assertSame('msg_auth_ok', $event['headers']['causation_id']);
        $this->assertSame('idem_int_auth_ok', $event['headers']['idempotency_key']);
        $this->assertSame(1, Payment::query()->where('payment_id', $paymentId)->count());
    }

    public function test_authorization_request_publishes_declined_event_for_insufficient_funds(): void
    {
        $paymentId = $this->uniquePaymentId('pay_int_auth_decline');

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.authorization.requested.v1',
            headers: $this->requestHeaders('auth_decline', 'idem_int_auth_decline'),
            payload: $this->authorizationPayload($paymentId, [
                'payment_method' => ['type' => 'card', 'token' => 'tok_insufficient_funds'],
                'amount' => ['value' => 50_000, 'currency' => 'EUR'],
            ]),
        );

        $this->assertTrue($this->rabbitMq->processNextRequest());

        $event = $this->rabbitMq->waitForResultEvent(
            fn (array $event): bool => ($event['payload']['payment_id'] ?? null) === $paymentId,
        );

        $this->assertNotNull($event);
        $this->assertSame('declined', $event['payload']['status']);
        $this->assertSame('INSUFFICIENT_FUNDS', $event['payload']['reason_code']);
    }

    public function test_duplicate_authorization_request_replays_same_outbound_event(): void
    {
        $paymentId = $this->uniquePaymentId('pay_int_auth_idem');
        $idempotencyKey = 'idem_int_auth_idem';

        $headers = $this->requestHeaders('auth_idem_1', $idempotencyKey);

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.authorization.requested.v1',
            headers: array_merge($headers, ['message_id' => 'msg_auth_idem_1']),
            payload: $this->authorizationPayload($paymentId),
        );

        $this->rabbitMq->publishRequest(
            routingKey: 'payment.authorization.requested.v1',
            headers: array_merge($headers, ['message_id' => 'msg_auth_idem_2']),
            payload: $this->authorizationPayload($paymentId),
        );

        $this->assertSame(2, $this->rabbitMq->processAllPendingRequests(2));

        $events = [];
        $deadline = microtime(true) + 5;

        while (count($events) < 2 && microtime(true) < $deadline) {
            $event = $this->rabbitMq->waitForResultEvent(
                fn (array $event): bool => ($event['payload']['payment_id'] ?? null) === $paymentId,
                timeoutMs: 500,
            );

            if ($event !== null) {
                $events[] = $event;
            }
        }

        $this->assertCount(2, $events);
        $this->assertSame($events[0]['headers']['message_id'], $events[1]['headers']['message_id']);
        $this->assertSame($events[0]['payload'], $events[1]['payload']);
        $this->assertSame(1, Payment::query()->where('payment_id', $paymentId)->first()?->attempts()->count());
    }
}
