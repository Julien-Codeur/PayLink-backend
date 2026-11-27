<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Messenger;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\ExpirePayment;
use App\Payment\Domain\Service\SettlePayment;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Payment\Infrastructure\Time\PaymentTimeout;
use App\Shared\Http\ApiProblemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ExpirePaymentHandler
{
    public function __construct(
        private readonly SettlePayment $settle,
        private readonly TransactionRepository $transactions,
        private readonly TransactionEventRepository $events,
        private readonly PaymentTimeout $timeout,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpirePayment $message): void
    {
        if (!Uuid::isValid($message->transactionId)) {
            return;
        }

        $id = Uuid::fromString($message->transactionId);
        $transaction = $this->transactions->find($id);
        if (!$transaction instanceof Transaction || TransactionStatus::Pending !== $transaction->getStatus()) {
            return;
        }

        $pendingAt = $this->events->pendingAt($transaction);
        if (!$pendingAt instanceof \DateTimeImmutable) {
            return;
        }

        $deadline = $pendingAt->modify(\sprintf('+%d seconds', $this->timeout->seconds()));
        if ($this->clock->now() < $deadline) {
            return;
        }

        try {
            $this->settle->expire($id);
        } catch (ApiProblemException $exception) {
            if (404 === $exception->statusCode) {
                return;
            }

            throw $exception;
        }
    }
}
