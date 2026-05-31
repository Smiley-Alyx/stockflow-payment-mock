<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Support\GeneratesPrefixedId;
use Database\Factories\AuthorizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Authorization extends Model
{
    /** @use HasFactory<AuthorizationFactory> */
    use GeneratesPrefixedId, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'authorization_id',
        'payment_id',
        'payment_attempt_id',
        'amount_value',
        'amount_currency',
        'status',
        'reason_code',
        'reason_message',
        'authorized_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AuthorizationStatus::class,
            'amount_value' => 'integer',
            'authorized_at' => 'datetime',
        ];
    }

    protected static function idPrefix(): string
    {
        return 'auth';
    }

    protected function externalIdColumn(): ?string
    {
        return 'authorization_id';
    }

    protected static function newFactory(): AuthorizationFactory
    {
        return AuthorizationFactory::new();
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function captures(): HasMany
    {
        return $this->hasMany(Capture::class);
    }
}
