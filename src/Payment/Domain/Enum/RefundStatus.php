<?php

declare(strict_types=1);

namespace App\Payment\Domain\Enum;

enum RefundStatus: string
{
    case Succeeded = 'SUCCEEDED';
}
