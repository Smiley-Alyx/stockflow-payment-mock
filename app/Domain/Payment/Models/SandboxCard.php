<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\SandboxCardBehavior;
use Illuminate\Database\Eloquent\Model;

class SandboxCard extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    protected $fillable = [
        'token',
        'behavior',
        'balance_value',
        'currency',
        'brand',
        'last_four',
        'is_expired',
        'is_blocked',
    ];

    protected function casts(): array
    {
        return [
            'behavior' => SandboxCardBehavior::class,
            'balance_value' => 'integer',
            'is_expired' => 'boolean',
            'is_blocked' => 'boolean',
        ];
    }
}
