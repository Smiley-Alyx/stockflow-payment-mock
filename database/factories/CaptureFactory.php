<?php

namespace Database\Factories;

use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Capture;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Capture>
 */
class CaptureFactory extends Factory
{
    protected $model = Capture::class;

    public function definition(): array
    {
        return [
            'capture_id' => Capture::generatePrefixedId('cap'),
            'payment_id' => Payment::factory(),
            'payment_attempt_id' => PaymentAttempt::factory(),
            'authorization_id' => Authorization::factory(),
            'amount_value' => fake()->numberBetween(100, 50000),
            'amount_currency' => 'EUR',
            'status' => CaptureStatus::Completed,
            'captured_at' => now(),
        ];
    }
}
