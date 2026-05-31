<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\RabbitMq\DlqRequeueService;
use Illuminate\Console\Command;

class RequeueDlqCommand extends Command
{
    protected $signature = 'payment-mock:requeue-dlq {--limit=10 : Maximum number of DLQ messages to requeue}';

    protected $description = 'Move messages from the payment requests DLQ back to the main requests exchange';

    public function handle(DlqRequeueService $dlqRequeueService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $dlqRequeueService->requeue($limit);

        $this->info(sprintf(
            'Requeued %d message(s). %d remaining in DLQ.',
            $result['requeued'],
            $result['remaining'],
        ));

        return self::SUCCESS;
    }
}
