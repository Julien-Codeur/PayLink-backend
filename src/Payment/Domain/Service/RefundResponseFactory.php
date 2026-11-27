<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Payment\Domain\Entity\Refund;
use App\Payment\Domain\Entity\Transaction;

final class RefundResponseFactory
{
    public function encode(Refund $refund, Transaction $transaction): string
    {
        $encoded = json_encode([
            'id' => (string) $refund->getId(),
            'transaction_id' => (string) $transaction->getId(),
            'amount' => $refund->getAmount(),
            'status' => $refund->getStatus()->value,
            'currency' => $transaction->getCurrency(),
            'transaction_status' => $transaction->getStatus()->value,
            'created_at' => $refund->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $encoded;
    }
}
