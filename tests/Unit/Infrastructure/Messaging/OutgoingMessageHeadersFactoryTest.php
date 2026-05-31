<?php

namespace Tests\Unit\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;
use App\Infrastructure\Messaging\RabbitMq\MessageHeaders;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OutgoingMessageHeadersFactoryTest extends TestCase
{
    public function test_builds_response_headers_from_incoming_message(): void
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

        $headers = (new OutgoingMessageHeadersFactory)->forResponse($incoming);

        $this->assertStringStartsWith('msg_', $headers->messageId);
        $this->assertNotSame('msg_auth_req_001', $headers->messageId);
        $this->assertSame('cor_checkout_001', $headers->correlationId);
        $this->assertSame('msg_auth_req_001', $headers->causationId);
        $this->assertSame('idem_auth_pay_001', $headers->idempotencyKey);
        $this->assertSame('v1', $headers->schemaVersion);
        $this->assertSame('2026-05-31T10:15:01Z', $headers->occurredAt);
        $this->assertSame('stockflow-payment-mock', $headers->producer);
    }
}
