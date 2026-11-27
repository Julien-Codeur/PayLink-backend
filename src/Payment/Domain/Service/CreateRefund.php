<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Entity\Refund;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Model\CreateRefundCommand;
use App\Payment\Domain\Model\RefundResult;
use App\Payment\Infrastructure\Repository\RefundRepository;
use App\Shared\Http\ApiProblemException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class CreateRefund
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly RefundRepository $refunds,
        private readonly RefundPolicy $policy,
        private readonly RefundResponseFactory $responses,
        private readonly TransactionLifecycle $lifecycle,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(Merchant $merchant, string $transactionId, CreateRefundCommand $command): RefundResult
    {
        $manager = $this->manager();
        $manager->beginTransaction();

        try {
            $result = $this->insertOrReplay($manager, $merchant, $transactionId, $command);
            $manager->commit();

            return $result;
        } catch (UniqueConstraintViolationException) {
            $this->rollback($manager);
            $this->managerRegistry->resetManager();

            return $this->replayAfterConflict($merchant, $transactionId, $command);
        } catch (\Throwable $throwable) {
            $this->rollback($manager);
            $this->managerRegistry->resetManager();

            throw $throwable;
        }
    }

    private function insertOrReplay(
        EntityManagerInterface $manager,
        Merchant $merchant,
        string $transactionId,
        CreateRefundCommand $command,
    ): RefundResult {
        $transaction = $this->lockedTransaction($manager, $merchant, $transactionId);
        $existing = $this->refunds->findOneByTransactionAndKey($transaction, $command->idempotencyKey);
        if ($existing instanceof Refund) {
            return $this->decide($existing, $command->requestHash);
        }

        $accepted = $this->refunds->sumSucceeded($transaction);
        $decision = $this->policy->decide(
            $transaction->getStatus(),
            $transaction->getAmount(),
            $accepted,
            $command->amount,
        );
        $transition = $this->transition($decision);
        $transaction->prepareRefund($accepted, $command->amount);
        $refund = Refund::succeed(
            $transaction,
            $command->amount,
            $command->idempotencyKey,
            $command->requestHash,
            $this->clock->now(),
        );
        $manager->persist($refund);
        $this->lifecycle->apply($transaction, $transition, $this->reason($decision));
        $refund->rememberResponse(201, $this->responses->encode($refund, $transaction));
        $manager->flush();

        return new RefundResult($refund->getIdempotencyResponseBody(), 201, false);
    }

    private function replayAfterConflict(Merchant $merchant, string $transactionId, CreateRefundCommand $command): RefundResult
    {
        $existing = $this->findCommitted($merchant, $transactionId, $command->idempotencyKey);
        if (!$existing instanceof Refund) {
            $existing = $this->manager()->wrapInTransaction(
                fn (): ?Refund => $this->findCommitted($merchant, $transactionId, $command->idempotencyKey),
            );
        }
        if (!$existing instanceof Refund) {
            throw ApiProblemException::unavailable();
        }

        return $this->decide($existing, $command->requestHash);
    }

    private function findCommitted(Merchant $merchant, string $transactionId, string $idempotencyKey): ?Refund
    {
        try {
            $uuid = Uuid::fromString($transactionId);
        } catch (\InvalidArgumentException) {
            throw ApiProblemException::notFound();
        }

        $transaction = $this->manager()->find(Transaction::class, $uuid);
        if (!$transaction instanceof Transaction || !$transaction->getMerchant()->getId()->equals($merchant->getId())) {
            throw ApiProblemException::notFound();
        }

        return $this->refunds->findOneByTransactionAndKey($transaction, $idempotencyKey);
    }

    private function lockedTransaction(EntityManagerInterface $manager, Merchant $merchant, string $transactionId): Transaction
    {
        try {
            $uuid = Uuid::fromString($transactionId);
        } catch (\InvalidArgumentException) {
            throw ApiProblemException::notFound();
        }

        $transaction = $manager->find(Transaction::class, $uuid, LockMode::PESSIMISTIC_WRITE);
        if (!$transaction instanceof Transaction || !$transaction->getMerchant()->getId()->equals($merchant->getId())) {
            throw ApiProblemException::notFound();
        }

        $owner = $transaction->getMerchant();
        $manager->refresh($owner);
        if ($owner->isSuspended()) {
            throw ApiProblemException::merchantSuspended();
        }

        return $transaction;
    }

    private function decide(Refund $existing, string $requestHash): RefundResult
    {
        $stored = $existing->getRequestHash();
        if (\strlen($stored) !== \strlen($requestHash) || !hash_equals($stored, $requestHash)) {
            throw ApiProblemException::idempotencyConflict();
        }

        return new RefundResult(
            $existing->getIdempotencyResponseBody(),
            $existing->getIdempotencyResponseStatus(),
            true,
        );
    }

    private function transition(RefundDecision $decision): string
    {
        return match ($decision) {
            RefundDecision::Partial => TransactionTransition::REFUND_PARTIAL,
            RefundDecision::Full => TransactionTransition::REFUND_FULL,
            RefundDecision::NotAllowed => throw ApiProblemException::refundNotAllowed(),
            RefundDecision::ExceedsBalance => throw ApiProblemException::refundExceedsBalance(),
            RefundDecision::InvalidAmount => throw ApiProblemException::validation('Le montant minimum est 1 XOF.', '/amount'),
        };
    }

    private function reason(RefundDecision $decision): string
    {
        return match ($decision) {
            RefundDecision::Partial => TransitionReason::REFUND_PARTIAL,
            RefundDecision::Full => TransitionReason::REFUND_FULL,
            default => throw new \LogicException('Décision de remboursement sans transition.'),
        };
    }

    private function rollback(EntityManagerInterface $manager): void
    {
        $connection = $manager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
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
