<?php

namespace Tests\Concerns;

use App\Domain\Payment\DTO\AuthorizationRequest;
use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\Enums\CaptureMode;
use App\Domain\Payment\Services\Authorization\PaymentAuthorizationService;
use Illuminate\Support\Str;

trait AuthorizesPayments
{
    protected function authorizePayment(
        string $paymentId,
        string $token = 'tok_approved_visa',
        int $amount = 12_990,
        string $idempotencyKey = 'idem-auth-default',
    ): AuthorizationResult {
        return $this->app->make(PaymentAuthorizationService::class)->authorize(
            new AuthorizationRequest(
                paymentId: $paymentId,
                orderId: 'ord_'.Str::lower(Str::random(8)),
                customerId: 'cust_'.Str::lower(Str::random(8)),
                amountValue: $amount,
                amountCurrency: 'EUR',
                paymentMethodType: 'card',
                paymentMethodToken: $token,
                captureMode: CaptureMode::Manual,
                idempotencyKey: $idempotencyKey,
            ),
        );
    }
}
