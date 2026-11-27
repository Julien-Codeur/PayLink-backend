<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

final class TransactionTransition
{
    public const START = 'start';
    public const SUCCEED = 'succeed';
    public const FAIL = 'fail';
    public const REFUND_PARTIAL = 'refund_partial';
    public const REFUND_FULL = 'refund_full';
}
