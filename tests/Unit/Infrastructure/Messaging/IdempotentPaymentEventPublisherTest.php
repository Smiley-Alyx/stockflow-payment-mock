<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Application\Mappers\PaymentEventPayloadMapper;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PublishedEventRecord;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\PublishedEventConflictException;
use App\Infrastructure\Messaging\RabbitMq\IdempotentPaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use App\Infrastructure\Messaging\RabbitMq\PublishedEventStore;
use App\Infrastructure\Messaging\RabbitMq\PublishedPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Support\Messaging\RecordingRabbitMqMessagePublisher;
use Tests\TestCase;

class IdempotentPaymentEventPublisherTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_stores_event_on_first_publish(): void
    {
        Carbon::setTestNow('2026-05-31T10:15:01Z');

        $recording = new RecordingRabbitMqMessagePublisher;
        $publisher = $this->publisher($recording);
        $incoming = $this->incoming();
        $result = $this->authorizationResult();

        $publisher->publishAuthorizationResult($incoming, $result);

        $this->assertCount(1, $recording->published);
        $this->assertSame(1, PublishedEventRecord::query()->count());
        $this->assertSame(
            $recording->published[0]->headers->messageId,
            PublishedEventRecord::query()->value('message_id'),
        );
    }

    public function test_replays_identical_outbound_event_on_duplicate_request(): void
    {
        Carbon::setTestNow('2026-05-31T10:15:01Z');

        $recording = new RecordingRabbitMqMessagePublisher;
        $publisher = $this->publisher($recording);
        $incoming = $this->incoming();
        $result = $this->authorizationResult();

        $publisher->publishAuthorizationResult($incoming, $result);

        $replayResult = new AuthorizationResult(
            payment: $result->payment,
            attempt: $result->attempt,
            authorization: $result->authorization,
            approved: true,
            idempotentReplay: true,
        );

        $publisher->publishAuthorizationResult($incoming, $replayResult);

        $this->assertCount(2, $recording->published);
        $this->assertSame(
            $recording->published[0]->routingKey,
            $recording->published[1]->routingKey,
        );
        $this->assertSame(
            $recording->published[0]->headers->messageId,
            $recording->published[1]->headers->messageId,
        );
        $this->assertSame($recording->published[0]->payload, $recording->published[1]->payload);
        $this->assertSame(1, PublishedEventRecord::query()->count());
    }

    public function test_rejects_conflicting_outbound_fingerprint(): void
    {
        Carbon::setTestNow('2026-05-31T10:15:01Z');

        $recording = new RecordingRabbitMqMessagePublisher;
        $publisher = $this->publisher($recording);
        $incoming = $this->incoming();
        $approved = $this->authorizationResult();

        $publisher->publishAuthorizationResult($incoming, $approved);

        $declinedPayment = new Payment([
            'payment_id' => 'pay_demo_001',
            'order_id' => 'ord_demo_001',
            'status' => PaymentStatus::AuthorizationDeclined,
        ]);

        $this->expectException(PublishedEventConflictException::class);

        $publisher->publishAuthorizationResult($incoming, new AuthorizationResult(
            payment: $declinedPayment,
            attempt: new PaymentAttempt,
            authorization: null,
            approved: false,
            idempotentReplay: true,
        ));
    }

    public function test_publishes_authorization_result_event(): void
    {
        Carbon::setTestNow('2026-05-31T10:15:01Z');

        $messagePublisher = Mockery::mock(\App\Infrastructure\Messaging\RabbitMq\RabbitMqMessagePublisher::class);
        $messagePublisher->shouldReceive('publish')
            ->once()
            ->with(Mockery::on(function (PublishedPaymentEvent $event): bool {
                return $event->routingKey === 'payment.authorization.approved.v1'
                    && $event->headers->correlationId === 'cor_checkout_001'
                    && $event->headers->causationId === 'msg_auth_req_001'
                    && $event->payload['payment_id'] === 'pay_demo_001';
            }));

        $publisher = new IdempotentPaymentEventPublisher(
            new PaymentEventPayloadMapper(new OutgoingMessageHeadersFactory),
            $messagePublisher,
            new PublishedEventStore,
            $this->app->make(PaymentIdempotencyService::class),
        );

        $publisher->publishAuthorizationResult($this->incoming(), $this->authorizationResult());
    }

    private function publisher(RecordingRabbitMqMessagePublisher $recording): IdempotentPaymentEventPublisher
    {
        return new IdempotentPaymentEventPublisher(
            new PaymentEventPayloadMapper(new OutgoingMessageHeadersFactory),
            $recording,
            new PublishedEventStore,
            $this->app->make(PaymentIdempotencyService::class),
        );
    }

    private function incoming(): IncomingMessage
    {
        return new IncomingMessage(
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
    }

    private function authorizationResult(): AuthorizationResult
    {
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

        return new AuthorizationResult(
            payment: $payment,
            attempt: new PaymentAttempt,
            authorization: $authorization,
            approved: true,
            idempotentReplay: false,
        );
    }
}
