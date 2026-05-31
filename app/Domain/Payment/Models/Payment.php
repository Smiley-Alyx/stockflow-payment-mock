<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\CaptureMode;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Support\GeneratesPrefixedId;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use GeneratesPrefixedId, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'payment_id',
        'order_id',
        'customer_id',
        'amount_value',
        'amount_currency',
        'status',
        'capture_mode',
        'payment_method_type',
        'payment_method_token',
        'metadata',
        'authorized_at',
        'captured_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'capture_mode' => CaptureMode::class,
            'metadata' => 'array',
            'amount_value' => 'integer',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function idPrefix(): string
    {
        return 'pay';
    }

    protected function externalIdColumn(): ?string
    {
        return 'payment_id';
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function authorization(): HasOne
    {
        return $this->hasOne(Authorization::class);
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class);
    }

    public function captures(): HasMany
    {
        return $this->hasMany(Capture::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function transitionTo(PaymentStatus $status): void
    {
        if (! $this->status->canTransitionTo($status)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot transition payment %s from %s to %s',
                $this->id,
                $this->status->value,
                $status->value,
            ));
        }

        $this->status = $status;
    }
}
