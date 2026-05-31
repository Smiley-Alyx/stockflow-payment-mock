<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Support\GeneratesPrefixedId;
use Database\Factories\CaptureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Capture extends Model
{
    /** @use HasFactory<CaptureFactory> */
    use GeneratesPrefixedId, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'capture_id',
        'payment_id',
        'payment_attempt_id',
        'authorization_id',
        'amount_value',
        'amount_currency',
        'status',
        'reason_code',
        'reason_message',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CaptureStatus::class,
            'amount_value' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    protected static function idPrefix(): string
    {
        return 'cap';
    }

    protected function externalIdColumn(): ?string
    {
        return 'capture_id';
    }

    protected static function newFactory(): CaptureFactory
    {
        return CaptureFactory::new();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
