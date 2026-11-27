<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Shared\Http\ApiProblemException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

final class SettlePayment
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly TransactionLifecycle $lifecycle,
        #[Target('transaction')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    public function callback(Uuid $id, string $outcome, ?string $failureCode): void
    {
        $transition = 'succeed' === $outcome ? TransactionTransition::SUCCEED : TransactionTransition::FAIL;
        $reason = $this->reason($outcome, $failureCode);

        $this->lock($id, true, function (Transaction $transaction) use ($transition, $reason, $failureCode): void {
            if ($this->workflow->can($transaction, $transition)) {
                $this->lifecycle->apply($transaction, $transition, $reason, $failureCode);

                return;
            }

            if (TransactionStatus::Initiated === $transaction->getStatus()) {
                throw ApiProblemException::invalidTransition();
            }
        });
    }

    public function expire(Uuid $id): void
    {
        $this->lock($id, false, function (Transaction $transaction): void {
            if (TransactionStatus::Pending !== $transaction->getStatus()) {
                return;
            }
            if (!$this->workflow->can($transaction, TransactionTransition::FAIL)) {
                return;
            }

            $this->lifecycle->apply(
                $transaction,
                TransactionTransition::FAIL,
                TransitionReason::TIMEOUT,
                FailureCode::Timeout->value,
            );
        });
    }

    /**
     * @param \Closure(Transaction): void $action
     */
    private function lock(Uuid $id, bool $missingIsError, \Closure $action): void
    {
        $manager = $this->manager();
        $manager->beginTransaction();

        try {
            $transaction = $manager->find(Transaction::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$transaction instanceof Transaction) {
                $manager->commit();
                if ($missingIsError) {
                    throw ApiProblemException::notFound('Transaction introuvable.');
                }

                return;
            }

            $action($transaction);
            $manager->commit();
        } catch (\Throwable $throwable) {
            if ($manager->getConnection()->isTransactionActive()) {
                $manager->rollback();
            }

            throw $throwable;
        }
    }

    private function reason(string $outcome, ?string $failureCode): string
    {
        if ('succeed' === $outcome) {
            return TransitionReason::SUCCEED;
        }

        if (FailureCode::Timeout->value === $failureCode) {
            return TransitionReason::TIMEOUT;
        }

        return TransitionReason::FAIL;
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
