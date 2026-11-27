<?php

declare(strict_types=1);

namespace App\Payment\Domain\Contract;

use App\Payment\Domain\Entity\Transaction;

interface PaymentProvider
{
    public function request(Transaction $transaction): void;
}
