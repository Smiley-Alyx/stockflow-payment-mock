<?php

namespace App\Domain\Payment\Services\Sandbox;

use App\Domain\Payment\Enums\SandboxCardBehavior;
use App\Domain\Payment\DTO\SandboxCardSnapshot;

class SandboxCardCatalog
{
    /**
     * @return list<array{
     *     token: string,
     *     behavior: SandboxCardBehavior,
     *     balance_value: int,
     *     currency: string,
     *     brand: string,
     *     last_four: string,
     *     is_expired: bool,
     *     is_blocked: bool,
     * }>
     */
    public function definitions(): array
    {
        return [
            [
                'token' => 'tok_approved_visa',
                'behavior' => SandboxCardBehavior::Approved,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '4242',
                'is_expired' => false,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_insufficient_funds',
                'behavior' => SandboxCardBehavior::InsufficientFunds,
                'balance_value' => 100,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '9995',
                'is_expired' => false,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_expired_card',
                'behavior' => SandboxCardBehavior::Expired,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '0069',
                'is_expired' => true,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_blocked_card',
                'behavior' => SandboxCardBehavior::Blocked,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '0005',
                'is_expired' => false,
                'is_blocked' => true,
            ],
            [
                'token' => 'tok_random_decline',
                'behavior' => SandboxCardBehavior::RandomDecline,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '0341',
                'is_expired' => false,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_processing_delay',
                'behavior' => SandboxCardBehavior::ProcessingDelay,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '3155',
                'is_expired' => false,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_provider_unavailable',
                'behavior' => SandboxCardBehavior::ProviderUnavailable,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '5100',
                'is_expired' => false,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_capture_failure',
                'behavior' => SandboxCardBehavior::CaptureFailure,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '8651',
                'is_expired' => false,
                'is_blocked' => false,
            ],
            [
                'token' => 'tok_refund_failure',
                'behavior' => SandboxCardBehavior::RefundFailure,
                'balance_value' => 10_000_000,
                'currency' => 'EUR',
                'brand' => 'visa',
                'last_four' => '7767',
                'is_expired' => false,
                'is_blocked' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['token'],
            $this->definitions(),
        );
    }

    public function findDefinition(string $token): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['token'] === $token) {
                return $definition;
            }
        }

        return null;
    }

    public function toSnapshot(array $definition): SandboxCardSnapshot
    {
        $masker = new SensitiveDataMasker;

        return new SandboxCardSnapshot(
            token: $definition['token'],
            behavior: $definition['behavior'],
            balanceValue: $definition['balance_value'],
            currency: $definition['currency'],
            brand: $definition['brand'],
            lastFour: $definition['last_four'],
            maskedPan: $masker->maskLastFour($definition['last_four']),
            isExpired: $definition['is_expired'],
            isBlocked: $definition['is_blocked'],
        );
    }
}
