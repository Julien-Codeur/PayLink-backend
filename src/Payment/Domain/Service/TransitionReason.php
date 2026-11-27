<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

final class TransitionReason
{
    public const START = 'Message pris en charge par le worker';
    public const SUCCEED = 'Simulateur répond OK';
    public const FAIL = 'Simulateur répond échec';
    public const TIMEOUT = 'Délai dépassé';
    public const REFUND_PARTIAL = 'Remboursement partiel';
    public const REFUND_FULL = 'Remboursement total';
}
