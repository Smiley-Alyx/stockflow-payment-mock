<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Application\Mappers\PaymentEventPayloadMapper;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use App\Infrastructure\Messaging\RabbitMq\PublishedPaymentEvent;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqMessagePublisher;
use App\Infrastructure\Messaging\RabbitMq\RabbitMqPaymentEventPublisher;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class RabbitMqPaymentEventPublisherTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_publishes_authorization_result_event(): void
    {
        Carbon::setTestNow('2026-05-31T10:15:01Z');

        $incoming = new IncomingMessage(
            routingKey: 'payment.authorization.requested.v1',
            headers: new MessageHeaders(
                messageId: 'msg_auth_req_001',
                correlationId: 'cor_checkout_001',
                causationId: 'msg_parent_001',
                idempotencyKey: 'idem_auth_pay_001',
                schemaVersion: 'v1',
                occurredAt: '2026-05-31T10:15:00Z',
                producer: 'stockflow-market',
            ),
            payload: [],
            body: '{}',
        );

        $payment = new Payment([
            'payment_id' => 'pay_demo_001',
            'order_id' => 'ord_demo_001',
            'status' => PaymentStatus::Authorized,
        ]);

        $authorization = new Authorization([
            'authorization_id' => 'auth_demo_001',
            'amount_value' => 12_990,
            'amount_currency' => 'EUR',
            'authorized_at' => Carbon::parse('2026-05-31T10:15:01Z'),
        ]);

        $result = new AuthorizationResult(
            payment: $payment,
            attempt: new PaymentAttempt,
            authorization: $authorization,
            approved: true,
            idempotentReplay: false,
        );

        $messagePublisher = Mockery::mock(RabbitMqMessagePublisher::class);
        $messagePublisher->shouldReceive('publish')
            ->once()
            ->with(Mockery::on(function (PublishedPaymentEvent $event): bool {
                return $event->routingKey === 'payment.authorization.approved.v1'
                    && $event->headers->correlationId === 'cor_checkout_001'
                    && $event->headers->causationId === 'msg_auth_req_001'
                    && $event->payload['payment_id'] === 'pay_demo_001';
            }));

        $publisher = new RabbitMqPaymentEventPublisher(
            new PaymentEventPayloadMapper(new OutgoingMessageHeadersFactory),
            $messagePublisher,
        );

        $publisher->publishAuthorizationResult($incoming, $result);
    }
}
