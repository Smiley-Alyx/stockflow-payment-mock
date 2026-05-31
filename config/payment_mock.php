<?php

return [
    'service_name' => env('PAYMENT_MOCK_SERVICE_NAME', 'stockflow-payment-mock'),
    'http_port' => (int) env('PAYMENT_MOCK_HTTP_PORT', 8080),
];
