<?php

namespace Tests\Unit\Domain\Payment\Sandbox;

use App\Domain\Payment\Enums\SandboxCardBehavior;
use App\Domain\Payment\Exceptions\InvalidSandboxCardTokenException;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsSandboxCards;
use Tests\TestCase;

class SandboxPaymentMethodResolverTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSandboxCards;

    private SandboxPaymentMethodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSandboxCards();
        $this->resolver = $this->app->make(SandboxPaymentMethodResolver::class);
    }

    public function test_resolve_returns_snapshot_for_known_token(): void
    {
        $card = $this->resolver->resolve('tok_approved_visa');

        $this->assertSame('tok_approved_visa', $card->token);
        $this->assertSame(SandboxCardBehavior::Approved, $card->behavior);
        $this->assertSame('visa', $card->brand);
        $this->assertStringEndsWith('4242', $card->maskedPan);
        $this->assertStringNotContainsString('4242424242424242', $card->maskedPan);
    }

    public function test_resolve_throws_for_unknown_token(): void
    {
        $this->expectException(InvalidSandboxCardTokenException::class);

        $this->resolver->resolve('tok_does_not_exist');
    }

    public function test_all_returns_every_seeded_card(): void
    {
        $this->assertCount(9, $this->resolver->all());
    }
}
