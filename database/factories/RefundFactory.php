<?php

namespace Database\Factories;

use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'refund_id' => Refund::generatePrefixedId('ref'),
            'payment_id' => Payment::factory(),
            'payment_attempt_id' => PaymentAttempt::factory(),
            'capture_id' => Capture::factory(),
            'amount_value' => fake()->numberBetween(100, 50000),
            'amount_currency' => 'EUR',
            'status' => RefundStatus::Completed,
            'refunded_at' => now(),
        ];
    }
}
