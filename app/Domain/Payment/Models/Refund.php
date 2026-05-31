<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Support\GeneratesPrefixedId;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use GeneratesPrefixedId, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'refund_id',
        'payment_id',
        'payment_attempt_id',
        'capture_id',
        'amount_value',
        'amount_currency',
        'status',
        'reason_code',
        'reason_message',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_value' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function idPrefix(): string
    {
        return 'ref';
    }

    protected function externalIdColumn(): ?string
    {
        return 'refund_id';
    }

    protected static function newFactory(): RefundFactory
    {
        return RefundFactory::new();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }
}
