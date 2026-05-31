<?php

namespace Tests\Unit\Application\Messaging;

use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Enums\CaptureMode;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use PHPUnit\Framework\TestCase;

class PaymentMessageMapperTest extends TestCase
{
    private PaymentMessageMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new PaymentMessageMapper;
    }

    public function test_maps_authorization_request_payload(): void
    {
        $message = $this->incoming('payment.authorization.requested.v1', [
            'payment_id' => 'pay_map_001',
            'order_id' => 'ord_map_001',
            'customer_id' => 'cust_map_001',
            'amount' => ['value' => 12990, 'currency' => 'EUR'],
            'payment_method' => ['type' => 'card', 'token' => 'tok_approved_visa'],
            'capture_mode' => 'manual',
            'metadata' => ['checkout_id' => 'chk_001'],
        ]);

        $request = $this->mapper->toAuthorizationRequest($message);

        $this->assertSame('pay_map_001', $request->paymentId);
        $this->assertSame(CaptureMode::Manual, $request->captureMode);
        $this->assertSame('idem_test_001', $request->idempotencyKey);
        $this->assertSame('cor_test_001', $request->correlationId);
    }

    public function test_maps_capture_request_payload(): void
    {
        $message = $this->incoming('payment.capture.requested.v1', [
            'payment_id' => 'pay_map_001',
            'amount' => ['value' => 5000, 'currency' => 'EUR'],
        ]);

        $request = $this->mapper->toCaptureRequest($message);

        $this->assertSame('pay_map_001', $request->paymentId);
        $this->assertSame(5000, $request->amountValue);
    }

    public function test_rejects_invalid_authorization_payload(): void
    {
        $this->expectException(InvalidMessageException::class);

        $this->mapper->toAuthorizationRequest(
            $this->incoming('payment.authorization.requested.v1', ['payment_id' => 'pay_map_001']),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function incoming(string $routingKey, array $payload): IncomingMessage
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
            payload: $payload,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
