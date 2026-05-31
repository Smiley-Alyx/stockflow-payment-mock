<?php

namespace App\Domain\Payment\Services\Sandbox;

/**
 * Maps well-known sandbox PAN patterns to predefined test tokens.
 * Never persists or logs raw card numbers.
 */
class SandboxTestCardTokenizer
{
    public function __construct(
        private readonly SensitiveDataMasker $masker,
        private readonly SandboxCardCatalog $catalog,
    ) {}

    /**
     * @return array{token: string, masked_pan: string}|null
     */
    public function tokenize(string $pan, ?string $cvv = null): ?array
    {
        $this->masker->mustNotPersistCvv($cvv);

        $digits = preg_replace('/\D+/', '', $pan) ?? '';

        if ($digits === '') {
            return null;
        }

        $token = $this->mapDigitsToToken($digits);

        if ($token === null || $this->catalog->findDefinition($token) === null) {
            return null;
        }

        return [
            'token' => $token,
            'masked_pan' => $this->masker->maskPan($digits),
        ];
    }

    private function mapDigitsToToken(string $digits): ?string
    {
        return match ($digits) {
            '4242424242424242' => 'tok_approved_visa',
            '4000000000009995' => 'tok_insufficient_funds',
            '4000000000000069' => 'tok_expired_card',
            '4000000000000005' => 'tok_blocked_card',
            '4000000000000341' => 'tok_random_decline',
            '4000000000003155' => 'tok_processing_delay',
            '4000000000005100' => 'tok_provider_unavailable',
            '4000000000008651' => 'tok_capture_failure',
            '4000000000007767' => 'tok_refund_failure',
            default => null,
        };
    }
}
