<?php

namespace Tests\Unit\Domain\Payment\Authorization;

use App\Domain\Payment\DTO\AuthorizationRequest;
use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\CaptureMode;
use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\IdempotencyRecord;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\SandboxCard;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use App\Domain\Payment\Services\Sandbox\SandboxBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class PaymentAuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSandboxCards;

    private PaymentAuthorizationService $service;

    private SandboxBalanceService $balanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
        $this->service = $this->app->make(PaymentAuthorizationService::class);
        $this->balanceService = $this->app->make(SandboxBalanceService::class);
    }

    public function test_authorization_approves_and_reserves_sandbox_balance(): void
    {
        $paymentId = 'pay_auth_approved_1';
        $amount = 12_990;
        $balanceBefore = $this->balanceService->currentBalance('tok_approved_visa');

        $result = $this->service->authorize($this->request(
            paymentId: $paymentId,
            token: 'tok_approved_visa',
            amount: $amount,
            idempotencyKey: 'idem-auth-approved-1',
        ));

        $this->assertTrue($result->approved);
        $this->assertFalse($result->idempotentReplay);
        $this->assertSame(PaymentStatus::Authorized, $result->payment->status);
        $this->assertSame(AuthorizationStatus::Approved, $result->authorization?->status);
        $this->assertSame($balanceBefore - $amount, $this->balanceService->currentBalance('tok_approved_visa'));
    }

    public function test_authorization_declines_insufficient_funds_without_balance_debit(): void
    {
        $balanceBefore = $this->balanceService->currentBalance('tok_insufficient_funds');

        $result = $this->service->authorize($this->request(
            paymentId: 'pay_auth_insufficient_1',
            token: 'tok_insufficient_funds',
            amount: 12_990,
            idempotencyKey: 'idem-auth-insufficient-1',
        ));

        $this->assertFalse($result->approved);
        $this->assertSame(DeclineReasonCode::InsufficientFunds, $result->declineReason);
        $this->assertSame(PaymentStatus::AuthorizationDeclined, $result->payment->status);
        $this->assertSame(AuthorizationStatus::Declined, $result->authorization?->status);
        $this->assertSame($balanceBefore, $this->balanceService->currentBalance('tok_insufficient_funds'));
    }

    public function test_authorization_declines_expired_card(): void
    {
        $result = $this->service->authorize($this->request(
            paymentId: 'pay_auth_expired_1',
            token: 'tok_expired_card',
            amount: 1_000,
            idempotencyKey: 'idem-auth-expired-1',
        ));

        $this->assertFalse($result->approved);
        $this->assertSame(DeclineReasonCode::CardExpired, $result->declineReason);
    }

    public function test_authorization_declines_blocked_card(): void
    {
        $result = $this->service->authorize($this->request(
            paymentId: 'pay_auth_blocked_1',
            token: 'tok_blocked_card',
            amount: 1_000,
            idempotencyKey: 'idem-auth-blocked-1',
        ));

        $this->assertFalse($result->approved);
        $this->assertSame(DeclineReasonCode::CardBlocked, $result->declineReason);
    }

    public function test_authorization_declines_invalid_card_token(): void
    {
        $balanceBefore = $this->balanceService->currentBalance('tok_approved_visa');

        $result = $this->service->authorize($this->request(
            paymentId: 'pay_auth_invalid_token_1',
            token: 'tok_unknown_card',
            amount: 1_000,
            idempotencyKey: 'idem-auth-invalid-token-1',
        ));

        $this->assertFalse($result->approved);
        $this->assertSame(DeclineReasonCode::InvalidCardToken, $result->declineReason);
        $this->assertSame($balanceBefore, $this->balanceService->currentBalance('tok_approved_visa'));
    }

    public function test_duplicate_idempotency_key_replays_without_second_balance_debit(): void
    {
        $paymentId = 'pay_auth_idempotent_1';
        $amount = 5_000;
        $idempotencyKey = 'idem-auth-duplicate-1';

        $first = $this->service->authorize($this->request(
            paymentId: $paymentId,
            token: 'tok_approved_visa',
            amount: $amount,
            idempotencyKey: $idempotencyKey,
        ));

        $balanceAfterFirst = $this->balanceService->currentBalance('tok_approved_visa');

        $second = $this->service->authorize($this->request(
            paymentId: $paymentId,
            token: 'tok_approved_visa',
            amount: $amount,
            idempotencyKey: $idempotencyKey,
        ));

        $this->assertTrue($first->approved);
        $this->assertTrue($second->approved);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame($first->attempt->id, $second->attempt->id);
        $this->assertSame($first->authorization?->id, $second->authorization?->id);
        $this->assertSame($balanceAfterFirst, $this->balanceService->currentBalance('tok_approved_visa'));
        $this->assertSame(1, IdempotencyRecord::query()->count());
        $this->assertSame(1, Payment::query()->where('payment_id', $paymentId)->count());
    }

    public function test_duplicate_declined_authorization_does_not_debit_balance(): void
    {
        $paymentId = 'pay_auth_idempotent_decline_1';
        $idempotencyKey = 'idem-auth-decline-duplicate-1';
        $balanceBefore = $this->balanceService->currentBalance('tok_insufficient_funds');

        $first = $this->service->authorize($this->request(
            paymentId: $paymentId,
            token: 'tok_insufficient_funds',
            amount: 50_000,
            idempotencyKey: $idempotencyKey,
        ));

        $second = $this->service->authorize($this->request(
            paymentId: $paymentId,
            token: 'tok_insufficient_funds',
            amount: 50_000,
            idempotencyKey: $idempotencyKey,
        ));

        $this->assertFalse($first->approved);
        $this->assertFalse($second->approved);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame($balanceBefore, $this->balanceService->currentBalance('tok_insufficient_funds'));
    }

    public function test_low_balance_card_declines_when_amount_exceeds_remaining_balance(): void
    {
        SandboxCard::query()
            ->where('token', 'tok_approved_visa')
            ->update(['balance_value' => 500]);

        $result = $this->service->authorize($this->request(
            paymentId: 'pay_auth_low_balance_1',
            token: 'tok_approved_visa',
            amount: 2_000,
            idempotencyKey: 'idem-auth-low-balance-1',
        ));

        $this->assertFalse($result->approved);
        $this->assertSame(DeclineReasonCode::InsufficientFunds, $result->declineReason);
        $this->assertSame(500, $this->balanceService->currentBalance('tok_approved_visa'));
    }

    private function request(
        string $paymentId,
        string $token,
        int $amount,
        string $idempotencyKey,
    ): AuthorizationRequest {
        return new AuthorizationRequest(
            paymentId: $paymentId,
            orderId: 'ord_'.Str::lower(Str::random(8)),
            customerId: 'cust_'.Str::lower(Str::random(8)),
            amountValue: $amount,
            amountCurrency: 'EUR',
            paymentMethodType: 'card',
            paymentMethodToken: $token,
            captureMode: CaptureMode::Manual,
            idempotencyKey: $idempotencyKey,
            messageId: 'msg_'.Str::lower(Str::ulid()->toString()),
            correlationId: 'cor_'.Str::lower(Str::ulid()->toString()),
            metadata: ['checkout_id' => 'chk_demo'],
        );
    }
}
