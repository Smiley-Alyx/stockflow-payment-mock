<?php

namespace App\Domain\Payment\Enums;

enum RefundStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
