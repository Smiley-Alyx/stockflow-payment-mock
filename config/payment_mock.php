<?php

return [
    'service_name' => env('PAYMENT_MOCK_SERVICE_NAME', 'stockflow-payment-mock'),
    'http_port' => (int) env('PAYMENT_MOCK_HTTP_PORT', 8080),

    'sandbox' => [
        'preferred_token_input' => true,
        'allow_test_pan_tokenization' => env('PAYMENT_MOCK_ALLOW_TEST_PAN_TOKENIZATION', false),
    ],

    'debug' => [
        'enabled' => env('PAYMENT_MOCK_DEBUG_ENABLED', env('APP_DEBUG', false)),
    ],
];
