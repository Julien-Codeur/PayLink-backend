<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Entity\TransactionEvent;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Shared\Http\ApiProblemException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\WorkflowInterface;

final class TransactionLifecycle
{
    public function __construct(
        #[Target('transaction')]
        private readonly WorkflowInterface $workflow,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function startIfStillInitiated(Transaction $transaction): void
    {
        if (TransactionStatus::Initiated !== $transaction->getStatus()) {
            return;
        }

        $this->apply($transaction, TransactionTransition::START, TransitionReason::START);
    }

    public function apply(Transaction $transaction, string $transition, string $reason, ?string $failureCode = null): void
    {
        $this->commit($transaction, $transition, $reason, $failureCode, true);
    }

    private function commit(Transaction $transaction, string $transition, string $reason, ?string $failureCode, bool $retry): void
    {
        if (!$this->workflow->can($transaction, $transition)) {
            $this->deny($transaction, $transition);
        }

        $from = $transaction->getStatus();
        $at = $this->clock->now();
        $manager = $this->manager();

        try {
            $this->workflow->apply($transaction, $transition, ['at' => $at]);
        } catch (LogicException) {
            $this->deny($transaction, $transition);
        }

        if ($from !== $transaction->getStatus()) {
            $manager->persist(TransactionEvent::record(
                $transaction,
                $from,
                $transaction->getStatus(),
                $reason,
                $at,
            ));
        }

        if (TransactionTransition::FAIL === $transition) {
            $transaction->recordFailure($failureCode ?? $reason);
        }

        try {
            $manager->flush();
        } catch (OptimisticLockException) {
            // Le retry recharge la transaction sans rappeler prepareRefund.
            // CreateRefund évite ce chemin grâce au verrou FOR UPDATE.
            $this->managerRegistry->resetManager();
            $fresh = $this->reload($transaction->getId());
            if (!$fresh instanceof Transaction) {
                $this->deny($transaction, $transition);
            }
            if (!$retry || !$this->workflow->can($fresh, $transition)) {
                $this->deny($fresh, $transition);
            }

            $this->commit($fresh, $transition, $reason, $failureCode, false);
        }
    }

    private function deny(Transaction $transaction, string $transition): never
    {
        $this->logger->warning('Transition de transaction refusée.', [
            'transaction_id' => (string) $transaction->getId(),
            'from_status' => $transaction->getStatus()->value,
            'transition' => $transition,
        ]);

        throw ApiProblemException::invalidTransition();
    }

    private function reload(Uuid $id): ?Transaction
    {
        $found = $this->manager()->find(Transaction::class, $id);

        return $found instanceof Transaction ? $found : null;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Gestionnaire de persistance indisponible.');
        }

        return $manager;
    }
}
