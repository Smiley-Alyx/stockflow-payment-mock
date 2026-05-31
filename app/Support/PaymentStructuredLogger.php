<?php

namespace App\Support;

class PaymentStructuredLogger
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function context(string $event, array $context = []): array
    {
        return array_merge([
            'service' => (string) config('payment_mock.service_name'),
            'event' => $event,
        ], $context);
    }
}
