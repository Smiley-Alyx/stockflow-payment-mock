<?php

namespace Tests\Feature\Messaging;

use App\Application\Handlers\AuthorizationRequestedHandler;
use App\Application\Handlers\CaptureRequestedHandler;
use App\Application\Handlers\PaymentMessageDispatcher;
use App\Application\Handlers\RefundRequestedHandler;
use App\Application\Mappers\PaymentEventPayloadMapper;
use App\Application\Mappers\PaymentMessageMapper;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PublishedEventRecord;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use App\Domain\Payment\Services\Capture\PaymentCaptureService;
use App\Domain\Payment\Services\Idempotency\PaymentIdempotencyService;
use App\Domain\Payment\Services\Refund\PaymentRefundService;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use App\Infrastructure\Messaging\RabbitMq\IdempotentPaymentEventPublisher;
use App\Infrastructure\Messaging\RabbitMq\OutgoingMessageHeadersFactory;
use App\Infrastructure\Messaging\RabbitMq\PublishedEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AuthorizesPayments;
use Tests\Concerns\BuildsPaymentMessages;
use Tests\Concerns\SeedsSandboxCards;
use Tests\Support\Messaging\RecordingRabbitMqMessagePublisher;
use Tests\TestCase;

class PaymentMessageIdempotencyTest extends TestCase
{
    use AuthorizesPayments;
    use BuildsPaymentMessages;
    use RefreshDatabase;
    use SeedsSandboxCards;

    private RecordingRabbitMqMessagePublisher $recordingPublisher;

    private PaymentMessageDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-31T10:15:01Z');
        $this->seedSandboxCards();

        $this->recordingPublisher = new RecordingRabbitMqMessagePublisher;
        $eventPublisher = new IdempotentPaymentEventPublisher(
            new PaymentEventPayloadMapper(new OutgoingMessageHeadersFactory),
            $this->recordingPublisher,
            new PublishedEventStore,
            $this->app->make(PaymentIdempotencyService::class),
            $this->app->make(\App\Domain\Payment\Services\Debug\ProviderDegradationSimulator::class),
            $this->app->make(\App\Infrastructure\Observability\PaymentMetricsRecorder::class),
        );

        $mapper = new PaymentMessageMapper;
        $metricsRecorder = $this->app->make(\App\Infrastructure\Observability\PaymentMetricsRecorder::class);

        $this->dispatcher = new PaymentMessageDispatcher(
            new AuthorizationRequestedHandler(
                $mapper,
                $this->app->make(PaymentAuthorizationService::class),
                $eventPublisher,
                $metricsRecorder,
            ),
            new CaptureRequestedHandler(
                $mapper,
                $this->app->make(PaymentCaptureService::class),
                $eventPublisher,
                $metricsRecorder,
            ),
            new RefundRequestedHandler(
                $mapper,
                $this->app->make(PaymentRefundService::class),
                $eventPublisher,
                $metricsRecorder,
            ),
        );
    }

    public function test_duplicate_authorization_message_replays_same_outbound_event_without_double_debit(): void
    {
        $paymentId = 'pay_msg_auth_idem_1';
        $idempotencyKey = 'idem-msg-auth-1';
        $balanceService = $this->app->make(SandboxBalanceService::class);
        $balanceBefore = $balanceService->currentBalance('tok_approved_visa');

        $this->dispatcher->dispatch($this->authorizationMessage(
            paymentId: $paymentId,
            idempotencyKey: $idempotencyKey,
            messageId: 'msg_auth_first',
        ));

        $this->dispatcher->dispatch($this->authorizationMessage(
            paymentId: $paymentId,
            idempotencyKey: $idempotencyKey,
            messageId: 'msg_auth_retry',
        ));

        $this->assertCount(2, $this->recordingPublisher->published);
        $this->assertSame(
            $this->recordingPublisher->published[0]->headers->messageId,
            $this->recordingPublisher->published[1]->headers->messageId,
        );
        $this->assertSame($this->recordingPublisher->published[0]->payload, $this->recordingPublisher->published[1]->payload);
        $this->assertSame(1, Payment::query()->where('payment_id', $paymentId)->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, PublishedEventRecord::query()->count());
        $this->assertSame($balanceBefore - 12_990, $balanceService->currentBalance('tok_approved_visa'));
    }

    public function test_duplicate_capture_message_replays_same_outbound_event(): void
    {
        $paymentId = 'pay_msg_cap_idem_1';
        $authKey = 'idem-msg-auth-cap-1';
        $captureKey = 'idem-msg-cap-1';

        $this->dispatcher->dispatch($this->authorizationMessage(
            paymentId: $paymentId,
            idempotencyKey: $authKey,
            messageId: 'msg_auth_for_cap',
        ));

        $this->recordingPublisher->published = [];

        $this->dispatcher->dispatch($this->captureMessage(
            paymentId: $paymentId,
            idempotencyKey: $captureKey,
            messageId: 'msg_cap_first',
        ));

        $this->dispatcher->dispatch($this->captureMessage(
            paymentId: $paymentId,
            idempotencyKey: $captureKey,
            messageId: 'msg_cap_retry',
        ));

        $this->assertCount(2, $this->recordingPublisher->published);
        $this->assertSame(
            $this->recordingPublisher->published[0]->headers->messageId,
            $this->recordingPublisher->published[1]->headers->messageId,
        );
        $this->assertSame('payment.capture.completed.v1', $this->recordingPublisher->published[0]->routingKey);
        $this->assertSame(1, PublishedEventRecord::query()->where('operation', PublishedEventRecord::OPERATION_CAPTURE)->count());
    }

    public function test_duplicate_refund_message_replays_same_outbound_event(): void
    {
        $paymentId = 'pay_msg_ref_idem_1';

        $this->dispatcher->dispatch($this->authorizationMessage(
            paymentId: $paymentId,
            idempotencyKey: 'idem-msg-auth-ref-1',
            messageId: 'msg_auth_for_ref',
        ));

        $this->dispatcher->dispatch($this->captureMessage(
            paymentId: $paymentId,
            idempotencyKey: 'idem-msg-cap-ref-1',
            messageId: 'msg_cap_for_ref',
        ));

        $this->recordingPublisher->published = [];

        $refundKey = 'idem-msg-ref-1';

        $this->dispatcher->dispatch($this->refundMessage(
            paymentId: $paymentId,
            idempotencyKey: $refundKey,
            messageId: 'msg_ref_first',
        ));

        $this->dispatcher->dispatch($this->refundMessage(
            paymentId: $paymentId,
            idempotencyKey: $refundKey,
            messageId: 'msg_ref_retry',
        ));

        $this->assertCount(2, $this->recordingPublisher->published);
        $this->assertSame(
            $this->recordingPublisher->published[0]->headers->messageId,
            $this->recordingPublisher->published[1]->headers->messageId,
        );
        $this->assertSame('payment.refund.completed.v1', $this->recordingPublisher->published[0]->routingKey);
        $this->assertSame(1, PublishedEventRecord::query()->where('operation', PublishedEventRecord::OPERATION_REFUND)->count());
    }
}
