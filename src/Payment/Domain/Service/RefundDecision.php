<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

enum RefundDecision
{
    case Partial;
    case Full;
    case NotAllowed;
    case ExceedsBalance;
    case InvalidAmount;
}
