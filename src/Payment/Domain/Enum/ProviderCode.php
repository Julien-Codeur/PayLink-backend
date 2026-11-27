<?php

declare(strict_types=1);

namespace App\Payment\Domain\Enum;

enum ProviderCode: string
{
    case MtnSim = 'MTN_SIM';
    case MoovSim = 'MOOV_SIM';
}
