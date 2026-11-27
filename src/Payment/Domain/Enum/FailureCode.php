<?php

declare(strict_types=1);

namespace App\Payment\Domain\Enum;

enum FailureCode: string
{
    case InsufficientFunds = 'insufficient_funds';
    case Timeout = 'timeout';
}
