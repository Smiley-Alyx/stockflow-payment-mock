<?php

namespace App\Infrastructure\Messaging\RabbitMq\Contracts;

use App\Domain\Payment\DTO\AuthorizationResult;
use App\Domain\Payment\DTO\CaptureResult;
use App\Domain\Payment\DTO\RefundResult;
use App\Infrastructure\Messaging\RabbitMq\IncomingMessage;

interface PaymentEventPublisher
{
    public function publishAuthorizationResult(IncomingMessage $incoming, AuthorizationResult $result): void;

    public function publishCaptureResult(IncomingMessage $incoming, CaptureResult $result): void;

    public function publishRefundResult(IncomingMessage $incoming, RefundResult $result): void;
}
