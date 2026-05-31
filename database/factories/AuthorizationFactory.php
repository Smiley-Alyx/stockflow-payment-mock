<?php

namespace Database\Factories;

use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Authorization>
 */
class AuthorizationFactory extends Factory
{
    protected $model = Authorization::class;

    public function definition(): array
    {
        return [
            'authorization_id' => Authorization::generatePrefixedId('auth'),
            'payment_id' => Payment::factory(),
            'payment_attempt_id' => PaymentAttempt::factory(),
            'amount_value' => fake()->numberBetween(100, 50000),
            'amount_currency' => 'EUR',
            'status' => AuthorizationStatus::Approved,
            'authorized_at' => now(),
        ];
    }
}
