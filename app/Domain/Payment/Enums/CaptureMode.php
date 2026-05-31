<?php

namespace App\Domain\Payment\Enums;

enum CaptureMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
