<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentAttemptType;
use App\Domain\Payment\Support\GeneratesPrefixedId;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use GeneratesPrefixedId, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'payment_id',
        'type',
        'status',
        'idempotency_key',
        'message_id',
        'correlation_id',
        'reason_code',
        'reason_message',
        'metadata',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentAttemptType::class,
            'status' => PaymentAttemptStatus::class,
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function idPrefix(): string
    {
        return 'atm';
    }

    protected static function newFactory(): PaymentAttemptFactory
    {
        return PaymentAttemptFactory::new();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function authorization(): HasOne
    {
        return $this->hasOne(Authorization::class);
    }

    public function capture(): HasOne
    {
        return $this->hasOne(Capture::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}
