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

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'stockflow'),
        'password' => env('RABBITMQ_PASSWORD', 'stockflow'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'stockflow.payment'),
        'dead_letter_exchange' => env('RABBITMQ_DLX', 'stockflow.payment.dlx'),
        'requests_queue' => env('RABBITMQ_REQUESTS_QUEUE', 'stockflow.payment.requests'),
        'retry_queue' => env('RABBITMQ_RETRY_QUEUE', 'stockflow.payment.requests.retry'),
        'dlq' => env('RABBITMQ_DLQ', 'stockflow.payment.requests.dlq'),
        'prefetch_count' => (int) env('RABBITMQ_PREFETCH_COUNT', 1),
        'consumer_timeout_seconds' => (int) env('RABBITMQ_CONSUMER_TIMEOUT_SECONDS', 30),
        'setup_topology' => env('RABBITMQ_SETUP_TOPOLOGY', true),
        'publish_events' => env('PAYMENT_MOCK_PUBLISH_EVENTS', true),
        'max_retry_attempts' => (int) env('RABBITMQ_MAX_RETRY_ATTEMPTS', 3),
        'retry_delay_ms' => (int) env('RABBITMQ_RETRY_DELAY_MS', 5000),
    ],
];
