<?php

namespace App\Domain\Payment\Enums;

enum AuthorizationStatus: string
{
    case Approved = 'approved';
    case Declined = 'declined';
}
