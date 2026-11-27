<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Payment\Domain\Enum\TransactionStatus;

final class RefundPolicy
{
    public function decide(TransactionStatus $status, int $transactionAmount, int $acceptedTotal, int $requested): RefundDecision
    {
        if ($requested < 1) {
            return RefundDecision::InvalidAmount;
        }

        if (TransactionStatus::Success !== $status && TransactionStatus::PartiallyRefunded !== $status) {
            return RefundDecision::NotAllowed;
        }

        $total = $acceptedTotal + $requested;
        if ($total > $transactionAmount) {
            return RefundDecision::ExceedsBalance;
        }

        if ($total === $transactionAmount) {
            return RefundDecision::Full;
        }

        return RefundDecision::Partial;
    }
}
