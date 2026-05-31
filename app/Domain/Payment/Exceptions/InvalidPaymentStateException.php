<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Payment\Enums\PaymentStatus;
use RuntimeException;

class InvalidPaymentStateException extends RuntimeException
{
    public function __construct(
        string $paymentId,
        PaymentStatus $currentStatus,
        string $operation,
    ) {
        parent::__construct(sprintf(
            'Payment %s in status %s cannot perform %s',
            $paymentId,
            $currentStatus->value,
            $operation,
        ));
    }
}
