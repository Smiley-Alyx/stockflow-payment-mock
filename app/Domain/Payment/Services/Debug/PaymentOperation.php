<?php

namespace App\Domain\Payment\Services\Debug;

enum PaymentOperation: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Refund = 'refund';
}
