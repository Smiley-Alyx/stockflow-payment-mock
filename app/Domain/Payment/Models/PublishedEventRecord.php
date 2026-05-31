<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Support\GeneratesPrefixedId;
use Illuminate\Database\Eloquent\Model;

class PublishedEventRecord extends Model
{
    use GeneratesPrefixedId;

    public const OPERATION_AUTHORIZATION = 'payment.authorization';

    public const OPERATION_CAPTURE = 'payment.capture';

    public const OPERATION_REFUND = 'payment.refund';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'operation',
        'payment_id',
        'idempotency_key',
        'routing_key',
        'message_id',
        'correlation_id',
        'causation_id',
        'schema_version',
        'occurred_at',
        'producer',
        'payload',
        'response_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    protected static function idPrefix(): string
    {
        return 'pev';
    }
}
