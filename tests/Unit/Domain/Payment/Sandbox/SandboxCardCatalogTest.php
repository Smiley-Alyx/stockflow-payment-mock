<?php

namespace Tests\Unit\Domain\Payment\Sandbox;

use App\Domain\Payment\Enums\SandboxCardBehavior;
use App\Domain\Payment\Services\Sandbox\SandboxCardCatalog;
use PHPUnit\Framework\TestCase;

class SandboxCardCatalogTest extends TestCase
{
    public function test_catalog_contains_all_required_sandbox_tokens(): void
    {
        $catalog = new SandboxCardCatalog;

        $this->assertSame([
            'tok_approved_visa',
            'tok_insufficient_funds',
            'tok_expired_card',
            'tok_blocked_card',
            'tok_random_decline',
            'tok_processing_delay',
            'tok_provider_unavailable',
            'tok_capture_failure',
            'tok_refund_failure',
        ], $catalog->tokens());
    }

    public function test_catalog_maps_tokens_to_expected_behaviors(): void
    {
        $catalog = new SandboxCardCatalog;

        $approved = $catalog->findDefinition('tok_approved_visa');
        $insufficient = $catalog->findDefinition('tok_insufficient_funds');

        $this->assertNotNull($approved);
        $this->assertSame(SandboxCardBehavior::Approved, $approved['behavior']);
        $this->assertNotNull($insufficient);
        $this->assertSame(SandboxCardBehavior::InsufficientFunds, $insufficient['behavior']);
        $this->assertLessThan($approved['balance_value'], $insufficient['balance_value']);
    }
}
