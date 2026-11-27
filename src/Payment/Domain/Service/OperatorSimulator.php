<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Model\OperatorDecision;

final class OperatorSimulator
{
    public function decide(string $phone): OperatorDecision
    {
        return match (substr($phone, -1)) {
            '0' => new OperatorDecision(true, false, 0, FailureCode::InsufficientFunds->value),
            '1' => new OperatorDecision(false, false, 0, FailureCode::Timeout->value),
            '9' => new OperatorDecision(true, true, 10, null),
            default => new OperatorDecision(true, true, 2, null),
        };
    }
}
