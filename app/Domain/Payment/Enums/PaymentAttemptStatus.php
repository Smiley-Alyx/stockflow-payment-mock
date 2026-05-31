<?php

namespace App\Domain\Payment\Enums;

enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
