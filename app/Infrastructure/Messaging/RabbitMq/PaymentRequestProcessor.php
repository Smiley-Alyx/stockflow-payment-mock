<?php

namespace App\Infrastructure\Messaging\RabbitMq;

use App\Application\Handlers\PaymentMessageDispatcher;
use App\Infrastructure\Messaging\RabbitMq\Exceptions\InvalidMessageException;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class PaymentRequestProcessor
{
    public function __construct(
        private readonly MessageHeaderValidator $headerValidator,
        private readonly PaymentMessageDispatcher $dispatcher,
        private readonly PaymentRequestFailureHandler $failureHandler,
    ) {}

    public function process(AMQPChannel $channel, AMQPMessage $message): void
    {
        try {
            $incoming = IncomingMessage::fromAmqpMessage($message, $this->headerValidator);

            Log::withContext([
                'correlation_id' => $incoming->headers->correlationId,
                'message_id' => $incoming->headers->messageId,
            ]);

            $this->dispatcher->dispatch($incoming);

            $channel->basic_ack($message->getDeliveryTag());
        } catch (InvalidMessageException $exception) {
            $this->failureHandler->handleInvalidMessage($channel, $message, $exception);
        } catch (Throwable $exception) {
            $this->failureHandler->handleProcessingFailure($channel, $message, $exception);
        } finally {
            Log::withoutContext();
        }
    }
}
