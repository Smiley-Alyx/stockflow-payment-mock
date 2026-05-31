<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Payment\Enums\DeclineReasonCode;
use RuntimeException;

class InvalidSandboxCardTokenException extends RuntimeException
{
    public function __construct(string $token)
    {
        parent::__construct(sprintf('Sandbox card token "%s" is invalid', $token));
    }

    public function reasonCode(): DeclineReasonCode
    {
        return DeclineReasonCode::InvalidCardToken;
    }
}
