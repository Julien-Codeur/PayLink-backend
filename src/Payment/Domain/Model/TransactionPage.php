<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

use App\Payment\Domain\Entity\Transaction;

final readonly class TransactionPage
{
    /**
     * @param list<Transaction> $items
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}
