<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Messenger;

use App\Payment\Domain\Contract\PaymentProvider;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\ExpirePayment;
use App\Payment\Domain\Message\ProcessPayment;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Payment\Infrastructure\Time\PaymentTimeout;
use App\Shared\Http\ApiProblemException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ProcessPaymentHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly TransactionLifecycle $lifecycle,
        private readonly PaymentProvider $provider,
        private readonly PaymentTimeout $timeout,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ProcessPayment $message): void
    {
        $transaction = $this->find($message->transactionId);
        if (!$transaction instanceof Transaction || TransactionStatus::Initiated !== $transaction->getStatus()) {
            return;
        }

        try {
            $this->lifecycle->startIfStillInitiated($transaction);
        } catch (ApiProblemException $exception) {
            if ('/errors/invalid-transition' === $exception->type) {
                return;
            }

            throw $exception;
        }

        if (TransactionStatus::Pending !== $transaction->getStatus()) {
            return;
        }

        $this->bus->dispatch(new ExpirePayment($message->transactionId), [
            new DelayStamp($this->timeout->seconds() * 1000),
        ]);
        $this->provider->request($transaction);
    }

    private function find(string $transactionId): ?Transaction
    {
        if (!Uuid::isValid($transactionId)) {
            return null;
        }

        $found = $this->transactions->find(Uuid::fromString($transactionId));

        return $found instanceof Transaction ? $found : null;
    }
}
