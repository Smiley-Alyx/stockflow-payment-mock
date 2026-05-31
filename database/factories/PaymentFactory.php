<?php

namespace Database\Factories;

use App\Domain\Payment\Enums\CaptureMode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $paymentId = Payment::generatePrefixedId('pay');

        return [
            'payment_id' => $paymentId,
            'order_id' => 'ord_'.fake()->unique()->numerify('########'),
            'customer_id' => 'cust_'.fake()->unique()->numerify('########'),
            'amount_value' => fake()->numberBetween(100, 50000),
            'amount_currency' => 'EUR',
            'status' => PaymentStatus::Created,
            'capture_mode' => CaptureMode::Manual,
            'payment_method_type' => 'card',
            'payment_method_token' => 'tok_approved_visa',
            'metadata' => ['checkout_id' => 'chk_'.fake()->numerify('######')],
        ];
    }

    public function authorized(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Authorized,
            'authorized_at' => now(),
        ]);
    }

    public function captured(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Captured,
            'authorized_at' => now()->subMinute(),
            'captured_at' => now(),
        ]);
    }
}
