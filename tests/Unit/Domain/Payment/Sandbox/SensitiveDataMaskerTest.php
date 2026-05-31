<?php

namespace Tests\Unit\Domain\Payment\Sandbox;

use App\Domain\Payment\Services\Sandbox\SensitiveDataMasker;
use PHPUnit\Framework\TestCase;

class SensitiveDataMaskerTest extends TestCase
{
    private SensitiveDataMasker $masker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masker = new SensitiveDataMasker;
    }

    public function test_mask_pan_keeps_only_last_four_digits(): void
    {
        $this->assertSame('************4242', $this->masker->maskPan('4242424242424242'));
    }

    public function test_redact_payload_removes_sensitive_fields(): void
    {
        $redacted = $this->masker->redactPayload([
            'token' => 'tok_approved_visa',
            'pan' => '4242424242424242',
            'cvv' => '123',
            'nested' => [
                'card_number' => '4000000000009995',
            ],
        ]);

        $this->assertSame('tok_approved_visa', $redacted['token']);
        $this->assertSame('[REDACTED]', $redacted['pan']);
        $this->assertSame('[REDACTED]', $redacted['cvv']);
        $this->assertSame('[REDACTED]', $redacted['nested']['card_number']);
    }

    public function test_must_not_persist_cvv_rejects_non_empty_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->masker->mustNotPersistCvv('123');
    }

    public function test_redact_free_text_masks_embedded_pan(): void
    {
        $message = 'authorization failed for card 4242424242424242';

        $this->assertStringNotContainsString('4242424242424242', $this->masker->redactFreeText($message));
        $this->assertStringContainsString('[REDACTED_PAN]', $this->masker->redactFreeText($message));
    }
}
