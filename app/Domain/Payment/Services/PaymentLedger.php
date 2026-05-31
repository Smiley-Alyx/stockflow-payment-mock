<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\AuthorizationStatus;
use App\Domain\Payment\Enums\CaptureStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Authorization;
use App\Domain\Payment\Models\Payment;

class PaymentLedger
{
    public function approvedAuthorization(Payment $payment): ?Authorization
    {
        return $payment->authorizations()
            ->where('status', AuthorizationStatus::Approved)
            ->latest('created_at')
            ->first();
    }

    public function capturedAmount(Payment $payment): int
    {
        return (int) $payment->captures()
            ->where('status', CaptureStatus::Completed)
            ->sum('amount_value');
    }

    public function refundedAmount(Payment $payment): int
    {
        return (int) $payment->refunds()
            ->where('status', RefundStatus::Completed)
            ->sum('amount_value');
    }

    public function refundableAmount(Payment $payment): int
    {
        return max(0, $this->capturedAmount($payment) - $this->refundedAmount($payment));
    }

    public function hasCompletedCapture(Payment $payment): bool
    {
        return $this->capturedAmount($payment) > 0;
    }
}
