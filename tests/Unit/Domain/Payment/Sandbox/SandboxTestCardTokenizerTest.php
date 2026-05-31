<?php

namespace Tests\Unit\Domain\Payment\Sandbox;

use App\Domain\Payment\Services\Sandbox\SandboxTestCardTokenizer;
use PHPUnit\Framework\TestCase;

class SandboxTestCardTokenizerTest extends TestCase
{
    private SandboxTestCardTokenizer $tokenizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenizer = new SandboxTestCardTokenizer(
            new \App\Domain\Payment\Services\Sandbox\SensitiveDataMasker,
            new \App\Domain\Payment\Services\Sandbox\SandboxCardCatalog,
        );
    }

    public function test_tokenize_maps_known_test_pan_to_predefined_token(): void
    {
        $result = $this->tokenizer->tokenize('4242 4242 4242 4242');

        $this->assertNotNull($result);
        $this->assertSame('tok_approved_visa', $result['token']);
        $this->assertSame('************4242', $result['masked_pan']);
    }

    public function test_tokenize_rejects_unknown_pan(): void
    {
        $this->assertNull($this->tokenizer->tokenize('4111111111111111'));
    }

    public function test_tokenize_rejects_cvv_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tokenizer->tokenize('4242424242424242', '123');
    }
}
