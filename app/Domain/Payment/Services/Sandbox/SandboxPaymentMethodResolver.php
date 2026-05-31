<?php

namespace App\Domain\Payment\Services\Sandbox;

use App\Domain\Payment\DTO\SandboxCardSnapshot;
use App\Domain\Payment\Exceptions\InvalidSandboxCardTokenException;
use App\Domain\Payment\Models\SandboxCard;

class SandboxPaymentMethodResolver
{
    public function __construct(
        private readonly SandboxCardCatalog $catalog,
        private readonly SensitiveDataMasker $masker,
    ) {}

    /**
     * @return list<SandboxCardSnapshot>
     */
    public function all(): array
    {
        return SandboxCard::query()
            ->orderBy('token')
            ->get()
            ->map(fn (SandboxCard $card): SandboxCardSnapshot => $this->toSnapshot($card))
            ->all();
    }

    public function resolve(string $token): SandboxCardSnapshot
    {
        $card = SandboxCard::query()->where('token', $token)->first();

        if ($card === null) {
            throw new InvalidSandboxCardTokenException($token);
        }

        return $this->toSnapshot($card);
    }

    public function exists(string $token): bool
    {
        return SandboxCard::query()->where('token', $token)->exists();
    }

    public function toSnapshot(SandboxCard $card): SandboxCardSnapshot
    {
        return new SandboxCardSnapshot(
            token: $card->token,
            behavior: $card->behavior,
            balanceValue: $card->balance_value,
            currency: $card->currency,
            brand: $card->brand,
            lastFour: $card->last_four,
            maskedPan: $this->masker->maskLastFour($card->last_four),
            isExpired: $card->is_expired,
            isBlocked: $card->is_blocked,
        );
    }
}
