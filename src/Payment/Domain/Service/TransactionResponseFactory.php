<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Entity\TransactionEvent;

final class TransactionResponseFactory
{
    public function encodeCreated(Transaction $transaction): string
    {
        $encoded = json_encode($this->created($transaction), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function created(Transaction $transaction): array
    {
        return [
            'id' => (string) $transaction->getId(),
            'status' => $transaction->getStatus()->value,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'phone' => $transaction->getPhone(),
            'provider' => $transaction->getProvider()->value,
            'merchant_reference' => $transaction->getMerchantReference(),
            'description' => $transaction->getDescription(),
            'created_at' => $transaction->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Transaction $transaction): array
    {
        return $this->created($transaction);
    }

    /**
     * @param list<TransactionEvent> $events
     *
     * @return array<string, mixed>
     */
    public function detail(Transaction $transaction, array $events): array
    {
        $history = [];
        foreach ($events as $event) {
            $history[] = [
                'id' => (string) $event->getId(),
                'from_status' => $event->getFromStatus()->value,
                'to_status' => $event->getToStatus()->value,
                'reason' => $event->getReason(),
                'created_at' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'id' => (string) $transaction->getId(),
            'status' => $transaction->getStatus()->value,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'phone' => $transaction->getPhone(),
            'provider' => $transaction->getProvider()->value,
            'merchant_reference' => $transaction->getMerchantReference(),
            'description' => $transaction->getDescription(),
            'failure_reason' => $transaction->getFailureReason(),
            'created_at' => $transaction->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $transaction->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'events' => $history,
        ];
    }
}
