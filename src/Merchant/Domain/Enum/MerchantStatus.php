<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Enum;

enum MerchantStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
