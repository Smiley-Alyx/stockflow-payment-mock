<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\PaymentRequestConsumer;
use Illuminate\Console\Command;

class ConsumePaymentRequestsCommand extends Command
{
    protected $signature = 'payment-mock:consume-requests';

    protected $description = 'Consume payment authorization, capture, and refund requests from RabbitMQ';

    public function handle(PaymentRequestConsumer $consumer): int
    {
        $this->info('Starting payment request consumer...');

        return $consumer->consume();
    }
}
