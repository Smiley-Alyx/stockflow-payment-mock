<?php

namespace Tests\Unit\Domain\Payment\Sandbox;

use App\Domain\Payment\Enums\DeclineReasonCode;
use App\Domain\Payment\Services\Sandbox\SandboxCardBehaviorEvaluator;
use App\Domain\Payment\Services\Sandbox\SandboxCardCatalog;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class SandboxCardBehaviorEvaluatorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSandboxCards;

    private SandboxCardBehaviorEvaluator $evaluator;

    private SandboxPaymentMethodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
        $this->evaluator = $this->app->make(SandboxCardBehaviorEvaluator::class);
        $this->resolver = $this->app->make(SandboxPaymentMethodResolver::class);
    }

    public function test_approved_card_has_no_authorization_decline(): void
    {
        $card = $this->resolver->resolve('tok_approved_visa');

        $this->assertNull($this->evaluator->authorizationDeclineReason($card, 12_990));
    }

    public function test_insufficient_funds_profile_declines_authorization(): void
    {
        $card = $this->resolver->resolve('tok_insufficient_funds');

        $this->assertSame(
            DeclineReasonCode::InsufficientFunds,
            $this->evaluator->authorizationDeclineReason($card, 12_990),
        );
    }

    public function test_expired_card_declines_with_card_expired_reason(): void
    {
        $card = $this->resolver->resolve('tok_expired_card');

        $this->assertSame(
            DeclineReasonCode::CardExpired,
            $this->evaluator->authorizationDeclineReason($card, 100),
        );
    }

    public function test_blocked_card_declines_with_card_blocked_reason(): void
    {
        $card = $this->resolver->resolve('tok_blocked_card');

        $this->assertSame(
            DeclineReasonCode::CardBlocked,
            $this->evaluator->authorizationDeclineReason($card, 100),
        );
    }

    public function test_random_decline_is_deterministic_with_roll(): void
    {
        $card = $this->resolver->resolve('tok_random_decline');

        $this->assertSame(
            DeclineReasonCode::Declined,
            $this->evaluator->authorizationDeclineReason($card, 100, randomRoll: 0),
        );
        $this->assertNull(
            $this->evaluator->authorizationDeclineReason($card, 100, randomRoll: 1),
        );
    }

    public function test_capture_and_refund_failure_profiles(): void
    {
        $captureCard = $this->resolver->resolve('tok_capture_failure');
        $refundCard = $this->resolver->resolve('tok_refund_failure');

        $this->assertSame(DeclineReasonCode::CaptureFailed, $this->evaluator->captureDeclineReason($captureCard));
        $this->assertSame(DeclineReasonCode::RefundFailed, $this->evaluator->refundDeclineReason($refundCard));
    }

    public function test_processing_delay_profile_exposes_delay_metadata(): void
    {
        $catalog = new SandboxCardCatalog;
        $definition = $catalog->findDefinition('tok_processing_delay');

        $this->assertNotNull($definition);
        $this->assertSame(2_000, $definition['behavior']->processingDelayMs());
    }
}
