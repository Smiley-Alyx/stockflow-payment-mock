<?php

namespace Tests\Unit\Application\Mappers;

use App\Application\Mappers\PaymentEventPayloadMapper;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentEventPayloadMapperTest extends TestCase
{
    private PaymentEventPayloadMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-31T10:15:01Z');
        $this->mapper = new PaymentEventPayloadMapper(new OutgoingMessageHeadersFactory);
    }

    public function test_maps_authorization_approved_event(): void
    {
        $payment = new Payment([
            'payment_id' => 'pay_demo_001',
            'order_id' => 'ord_demo_001',
        ]);

        $authorization = new Authorization([
            'authorization_id' => 'auth_demo_001',
            'amount_value' => 12_990,
            'amount_currency' => 'EUR',
            'status' => AuthorizationStatus::Approved,
            'authorized_at' => Carbon::parse('2026-05-31T10:15:01Z'),
        ]);

        $event = $this->mapper->authorizationResult(
            $this->incoming(),
            new AuthorizationResult(
                payment: $payment,
                attempt: new PaymentAttempt,
                authorization: $authorization,
                approved: true,
                idempotentReplay: false,
            ),
        );

        $this->assertSame('payment.authorization.approved.v1', $event->routingKey);
        $this->assertSame('cor_checkout_001', $event->headers->correlationId);
        $this->assertSame('msg_auth_req_001', $event->headers->causationId);
        $this->assertSame([
            'payment_id' => 'pay_demo_001',
            'authorization_id' => 'auth_demo_001',
            'order_id' => 'ord_demo_001',
            'status' => 'approved',
            'amount' => ['value' => 12_990, 'currency' => 'EUR'],
            'authorized_at' => '2026-05-31T10:15:01Z',
        ], $event->payload);
    }

    public function test_maps_authorization_declined_event(): void
    {
        $payment = new Payment([
            'payment_id' => 'pay_demo_002',
            'order_id' => 'ord_demo_002',
            'status' => PaymentStatus::AuthorizationDeclined,
        ]);

        $authorization = new Authorization([
            'status' => AuthorizationStatus::Declined,
            'reason_code' => DeclineReasonCode::InsufficientFunds->value,
            'reason_message' => DeclineReasonCode::InsufficientFunds->message(),
        ]);

        $event = $this->mapper->authorizationResult(
            $this->incoming(),
            new AuthorizationResult(
                payment: $payment,
                attempt: new PaymentAttempt,
                authorization: $authorization,
                approved: false,
                idempotentReplay: false,
                declineReason: DeclineReasonCode::InsufficientFunds,
            ),
        );

        $this->assertSame('payment.authorization.declined.v1', $event->routingKey);
        $this->assertSame([
            'payment_id' => 'pay_demo_002',
            'order_id' => 'ord_demo_002',
            'status' => 'declined',
            'reason_code' => 'INSUFFICIENT_FUNDS',
            'reason_message' => 'Sandbox card has insufficient funds',
        ], $event->payload);
    }

    public function test_maps_capture_completed_event(): void
    {
        $payment = new Payment(['payment_id' => 'pay_demo_001']);

        $capture = new Capture([
            'capture_id' => 'cap_demo_001',
            'authorization_id' => 'auth_demo_001',
            'amount_value' => 12_990,
            'amount_currency' => 'EUR',
            'status' => CaptureStatus::Completed,
            'captured_at' => Carbon::parse('2026-05-31T10:20:01Z'),
        ]);

        $event = $this->mapper->captureResult(
            $this->incoming('payment.capture.requested.v1'),
            new CaptureResult(
                payment: $payment,
                attempt: new PaymentAttempt,
                capture: $capture,
                completed: true,
                idempotentReplay: false,
            ),
        );

        $this->assertSame('payment.capture.completed.v1', $event->routingKey);
        $this->assertSame([
            'payment_id' => 'pay_demo_001',
            'capture_id' => 'cap_demo_001',
            'authorization_id' => 'auth_demo_001',
            'status' => 'completed',
            'amount' => ['value' => 12_990, 'currency' => 'EUR'],
            'captured_at' => '2026-05-31T10:20:01Z',
        ], $event->payload);
    }

    public function test_maps_capture_failed_event(): void
    {
        $payment = new Payment(['payment_id' => 'pay_demo_003']);

        $capture = new Capture([
            'capture_id' => 'cap_demo_003',
            'status' => CaptureStatus::Failed,
            'reason_code' => DeclineReasonCode::CaptureFailed->value,
            'reason_message' => DeclineReasonCode::CaptureFailed->message(),
        ]);

        $event = $this->mapper->captureResult(
            $this->incoming('payment.capture.requested.v1'),
            new CaptureResult(
                payment: $payment,
                attempt: new PaymentAttempt,
                capture: $capture,
                completed: false,
                idempotentReplay: false,
                failureReason: DeclineReasonCode::CaptureFailed,
            ),
        );

        $this->assertSame('payment.capture.failed.v1', $event->routingKey);
        $this->assertSame([
            'payment_id' => 'pay_demo_003',
            'capture_id' => 'cap_demo_003',
            'status' => 'failed',
            'reason_code' => 'CAPTURE_FAILED',
            'reason_message' => 'Sandbox capture failed',
        ], $event->payload);
    }

    public function test_maps_refund_completed_event(): void
    {
        $payment = new Payment(['payment_id' => 'pay_demo_001']);

        $refund = new Refund([
            'refund_id' => 'ref_demo_001',
            'capture_id' => 'cap_demo_001',
            'amount_value' => 5_000,
            'amount_currency' => 'EUR',
            'status' => RefundStatus::Completed,
            'refunded_at' => Carbon::parse('2026-05-31T11:00:01Z'),
        ]);

        $event = $this->mapper->refundResult(
            $this->incoming('payment.refund.requested.v1'),
            new RefundResult(
                payment: $payment,
                attempt: new PaymentAttempt,
                refund: $refund,
                completed: true,
                idempotentReplay: false,
            ),
        );

        $this->assertSame('payment.refund.completed.v1', $event->routingKey);
        $this->assertSame([
            'payment_id' => 'pay_demo_001',
            'refund_id' => 'ref_demo_001',
            'capture_id' => 'cap_demo_001',
            'status' => 'completed',
            'amount' => ['value' => 5_000, 'currency' => 'EUR'],
            'refunded_at' => '2026-05-31T11:00:01Z',
        ], $event->payload);
    }

    public function test_maps_refund_failed_event(): void
    {
        $payment = new Payment(['payment_id' => 'pay_demo_004']);
        $attempt = new PaymentAttempt([
            'reason_code' => DeclineReasonCode::RefundFailed->value,
            'reason_message' => 'Refund amount exceeds captured amount',
        ]);

        $event = $this->mapper->refundResult(
            $this->incoming('payment.refund.requested.v1'),
            new RefundResult(
                payment: $payment,
                attempt: $attempt,
                refund: null,
                completed: false,
                idempotentReplay: false,
                failureReason: DeclineReasonCode::RefundFailed,
            ),
        );

        $this->assertSame('payment.refund.failed.v1', $event->routingKey);
        $this->assertSame([
            'payment_id' => 'pay_demo_004',
            'status' => 'failed',
            'reason_code' => 'REFUND_FAILED',
            'reason_message' => 'Refund amount exceeds captured amount',
        ], $event->payload);
    }

    private function incoming(string $routingKey = 'payment.authorization.requested.v1'): IncomingMessage
    {
        return new IncomingMessage(
            routingKey: $routingKey,
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
}
