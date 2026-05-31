<?php

namespace App\Domain\Payment\Enums;

enum PaymentAttemptType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Refund = 'refund';
}
