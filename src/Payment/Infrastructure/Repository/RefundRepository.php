<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Repository;

use App\Payment\Domain\Entity\Refund;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\RefundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Refund> */
final class RefundRepository extends ServiceEntityRepository
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
        parent::__construct($registry, Refund::class);
    }

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->manager()->createQueryBuilder()
            ->select($alias)
            ->from(Refund::class, $alias, $indexBy);
    }

    public function findOneByTransactionAndKey(Transaction $transaction, string $idempotencyKey): ?Refund
    {
        return $this->findOneByTransactionId($transaction->getId(), $idempotencyKey);
    }

    public function findOneByTransactionId(Uuid $transactionId, string $idempotencyKey): ?Refund
    {
        $refund = $this->createQueryBuilder('refund')
            ->andWhere('IDENTITY(refund.transaction) = :transactionId')
            ->andWhere('refund.idempotencyKey = :idempotencyKey')
            ->setParameter('transactionId', $transactionId, 'uuid')
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->getQuery()
            ->getOneOrNullResult();

        return $refund instanceof Refund ? $refund : null;
    }

    public function sumSucceeded(Transaction $transaction): int
    {
        return (int) $this->createQueryBuilder('refund')
            ->select('COALESCE(SUM(refund.amount), 0)')
            ->andWhere('IDENTITY(refund.transaction) = :transactionId')
            ->andWhere('refund.status = :status')
            ->setParameter('transactionId', $transaction->getId(), 'uuid')
            ->setParameter('status', RefundStatus::Succeeded)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForTransaction(Uuid $transactionId): int
    {
        return (int) $this->createQueryBuilder('refund')
            ->select('COUNT(refund)')
            ->andWhere('IDENTITY(refund.transaction) = :transactionId')
            ->setParameter('transactionId', $transactionId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Refund::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Gestionnaire de persistance indisponible.');
        }

        return $manager;
    }
}
