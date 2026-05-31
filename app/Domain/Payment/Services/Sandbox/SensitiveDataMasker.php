<?php

namespace App\Domain\Payment\Services\Sandbox;

class SensitiveDataMasker
{
    private const PAN_PATTERN = '/\b(?:\d[ -]*?){13,19}\b/';

    private const CVV_PATTERN = '/\b\d{3,4}\b/';

    /**
     * @var list<string>
     */
    private const REDACTED_FIELDS = ['pan', 'card_number', 'cvv', 'cvc', 'security_code'];

    public function maskPan(string $pan): string
    {
        $digits = preg_replace('/\D+/', '', $pan) ?? '';

        if (strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }

    public function maskLastFour(string $lastFour): string
    {
        $digits = preg_replace('/\D+/', '', $lastFour) ?? '';

        if ($digits === '') {
            return '****';
        }

        return str_repeat('*', 12).substr($digits, -4);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactPayload(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveField($key)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactPayload($value);

                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = $this->redactFreeText($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    public function redactFreeText(string $value): string
    {
        $redacted = preg_replace(self::PAN_PATTERN, '[REDACTED_PAN]', $value) ?? $value;

        return preg_replace(self::CVV_PATTERN, '[REDACTED_CVV]', $redacted) ?? $redacted;
    }

    public function isSensitiveField(string $field): bool
    {
        return in_array(strtolower($field), self::REDACTED_FIELDS, true);
    }

    public function mustNotPersistCvv(?string $cvv): void
    {
        if ($cvv !== null && $cvv !== '') {
            throw new \InvalidArgumentException('CVV must never be stored in the payment mock.');
        }
    }
}
