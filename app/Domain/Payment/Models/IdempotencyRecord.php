<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Support\GeneratesPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyRecord extends Model
{
    use GeneratesPrefixedId;

    public const OPERATION_AUTHORIZATION = 'payment.authorization';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'operation',
        'idempotency_key',
        'payment_id',
        'payment_attempt_id',
        'response_fingerprint',
    ];

    protected static function idPrefix(): string
    {
        return 'idem';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }
}
