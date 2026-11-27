<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Model\CreateTransactionCommand;
use App\Payment\Domain\Model\TransactionResult;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Shared\Http\ApiProblemException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class CreateTransaction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly TransactionRepository $transactions,
        private readonly TransactionResponseFactory $responses,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(Merchant $merchant, CreateTransactionCommand $command): TransactionResult
    {
        $merchantId = $merchant->getId();

        try {
            return $this->entityManager->wrapInTransaction(
                fn (): TransactionResult => $this->insertOrReplay($merchantId, $command),
            );
        } catch (ApiProblemException $exception) {
            $this->managerRegistry->resetManager();

            throw $exception;
        } catch (UniqueConstraintViolationException) {
            $this->managerRegistry->resetManager();

            return $this->replayAfterConflict($merchantId, $command);
        }
    }

    private function insertOrReplay(Uuid $merchantId, CreateTransactionCommand $command): TransactionResult
    {
        $locked = $this->entityManager->find(Merchant::class, $merchantId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Merchant) {
            throw ApiProblemException::notFound('Marchand introuvable.');
        }
        if ($locked->isSuspended()) {
            throw ApiProblemException::merchantSuspended();
        }

        $existing = $this->transactions->findOneByMerchantAndKey($locked, $command->idempotencyKey);
        if ($existing instanceof Transaction) {
            return $this->decide($existing, $command->requestHash);
        }

        $transaction = Transaction::initiate(
            $locked,
            $command->amount,
            $command->phone,
            $command->provider,
            $command->merchantReference,
            $command->description,
            $command->idempotencyKey,
            $command->requestHash,
            $this->clock->now(),
        );
        $this->entityManager->persist($transaction);
        $transaction->rememberResponse(201, $this->responses->encodeCreated($transaction));

        return new TransactionResult($transaction->getIdempotencyResponseBody(), 201, false, (string) $transaction->getId());
    }

    private function replayAfterConflict(Uuid $merchantId, CreateTransactionCommand $command): TransactionResult
    {
        $existing = $this->findCommitted($merchantId, $command->idempotencyKey);
        if (!$existing instanceof Transaction) {
            $existing = $this->manager()->wrapInTransaction(
                fn (): ?Transaction => $this->findCommitted($merchantId, $command->idempotencyKey),
            );
        }
        if (!$existing instanceof Transaction) {
            throw ApiProblemException::unavailable();
        }

        return $this->decide($existing, $command->requestHash);
    }

    private function findCommitted(Uuid $merchantId, string $idempotencyKey): ?Transaction
    {
        $merchant = $this->manager()->find(Merchant::class, $merchantId);
        if (!$merchant instanceof Merchant) {
            throw ApiProblemException::notFound('Marchand introuvable.');
        }

        return $this->transactions->findOneByMerchantAndKey($merchant, $idempotencyKey);
    }

    private function decide(Transaction $existing, string $requestHash): TransactionResult
    {
        $stored = $existing->getRequestHash();
        if (\strlen($stored) !== \strlen($requestHash) || !hash_equals($stored, $requestHash)) {
            throw ApiProblemException::idempotencyConflict();
        }

        return new TransactionResult(
            $existing->getIdempotencyResponseBody(),
            $existing->getIdempotencyResponseStatus(),
            true,
            (string) $existing->getId(),
        );
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
