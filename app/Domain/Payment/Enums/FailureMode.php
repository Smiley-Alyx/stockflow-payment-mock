<?php

namespace App\Domain\Payment\Enums;

enum FailureMode: string
{
    case Normal = 'normal';
    case AlwaysDecline = 'always_decline';
    case RandomDecline = 'random_decline';
    case ProcessingDelay = 'processing_delay';
    case ProviderUnavailable = 'provider_unavailable';
    case Timeout = 'timeout';
    case CaptureFailure = 'capture_failure';
    case RefundFailure = 'refund_failure';
    case DuplicateResponse = 'duplicate_response';
    case PublishFailure = 'publish_failure';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $mode): string => $mode->value,
            self::cases(),
        );
    }
}
