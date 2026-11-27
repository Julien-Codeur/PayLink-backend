<?php

declare(strict_types=1);

namespace App\Payment\Domain\Enum;

enum TransactionStatus: string
{
    case Initiated = 'INITIATED';
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';
}
