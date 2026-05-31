<?php

namespace App\Domain\Payment\Enums;

enum CaptureStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
