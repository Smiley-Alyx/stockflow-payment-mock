<?php

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\AuthorizationRequestedHandler;
use App\Application\Handlers\CaptureRequestedHandler;
use App\Application\Handlers\PaymentMessageDispatcher;
use App\Application\Handlers\RefundRequestedHandler;
use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Domain\Payment\Services\Refund\PaymentRefundService;
use App\Infrastructure\Messaging\RabbitMq\Contracts\PaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class PaymentMessageDispatcherTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_dispatches_authorization_requested_message(): void
    {
        $incoming = $this->incoming('payment.authorization.requested.v1');

        $authorizationService = Mockery::mock(PaymentAuthorizationService::class);
        $authorizationService->shouldReceive('authorize')
            ->once()
            ->andReturn($this->authorizationResult());

        $publisher = Mockery::mock(PaymentEventPublisher::class);
        $publisher->shouldReceive('publishAuthorizationResult')->once();

        $metricsRecorder = $this->app->make(\App\Infrastructure\Observability\PaymentMetricsRecorder::class);

        $dispatcher = new PaymentMessageDispatcher(
            new AuthorizationRequestedHandler(new PaymentMessageMapper, $authorizationService, $publisher, $metricsRecorder),
            new CaptureRequestedHandler(new PaymentMessageMapper, Mockery::mock(PaymentCaptureService::class), $publisher, $metricsRecorder),
            new RefundRequestedHandler(new PaymentMessageMapper, Mockery::mock(PaymentRefundService::class), $publisher, $metricsRecorder),
        );

        $dispatcher->dispatch($incoming);
    }

    private function incoming(string $routingKey): IncomingMessage
    {
        return new IncomingMessage(
            routingKey: $routingKey,
            headers: new MessageHeaders(
                messageId: 'msg_test_001',
                correlationId: 'cor_test_001',
                causationId: 'msg_cause_001',
                idempotencyKey: 'idem_test_001',
                schemaVersion: 'v1',
                occurredAt: '2026-05-31T10:00:00Z',
                producer: 'stockflow-market',
            ),
            payload: [
                'payment_id' => 'pay_dispatch_001',
                'order_id' => 'ord_dispatch_001',
                'customer_id' => 'cust_dispatch_001',
                'amount' => ['value' => 1000, 'currency' => 'EUR'],
                'payment_method' => ['type' => 'card', 'token' => 'tok_approved_visa'],
                'capture_mode' => 'manual',
            ],
            body: '{}',
        );
    }

    private function authorizationResult(): AuthorizationResult
    {
        $payment = new \App\Domain\Payment\Models\Payment([
            'payment_id' => 'pay_dispatch_001',
            'status' => PaymentStatus::Authorized,
        ]);

        return new AuthorizationResult(
            payment: $payment,
            attempt: new \App\Domain\Payment\Models\PaymentAttempt,
            authorization: null,
            approved: true,
            idempotentReplay: false,
        );
    }
}
