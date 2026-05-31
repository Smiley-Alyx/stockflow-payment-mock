<?php

namespace Database\Factories;

use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentAttemptType;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'type' => PaymentAttemptType::Authorization,
            'status' => PaymentAttemptStatus::Pending,
            'idempotency_key' => (string) Str::uuid(),
            'message_id' => 'msg_'.strtolower((string) Str::ulid()),
            'correlation_id' => 'cor_'.strtolower((string) Str::ulid()),
        ];
    }
}
