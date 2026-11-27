<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Repository;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Model\TransactionListCriteria;
use App\Payment\Domain\Model\TransactionPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Transaction> */
final class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->manager()->createQueryBuilder()
            ->select($alias)
            ->from(Transaction::class, $alias, $indexBy);
    }

    public function findOneByMerchantAndKey(Merchant $merchant, string $idempotencyKey): ?Transaction
    {
        return $this->one(
            $this->createQueryBuilder('transaction')
                ->andWhere('IDENTITY(transaction.merchant) = :merchantId')
                ->andWhere('transaction.idempotencyKey = :idempotencyKey')
                ->setParameter('merchantId', $merchant->getId(), 'uuid')
                ->setParameter('idempotencyKey', $idempotencyKey),
        );
    }

    public function findOneForMerchant(Merchant $merchant, string $id): ?Transaction
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->one(
            $this->createQueryBuilder('transaction')
                ->andWhere('transaction.id = :id')
                ->andWhere('IDENTITY(transaction.merchant) = :merchantId')
                ->setParameter('id', $uuid, 'uuid')
                ->setParameter('merchantId', $merchant->getId(), 'uuid'),
        );
    }

    public function countByMerchantAndKey(Merchant $merchant, string $idempotencyKey): int
    {
        return (int) $this->createQueryBuilder('transaction')
            ->select('COUNT(transaction)')
            ->andWhere('IDENTITY(transaction.merchant) = :merchantId')
            ->andWhere('transaction.idempotencyKey = :idempotencyKey')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function search(Merchant $merchant, TransactionListCriteria $criteria): TransactionPage
    {
        $queryBuilder = $this->createQueryBuilder('transaction')
            ->andWhere('IDENTITY(transaction.merchant) = :merchantId')
            ->setParameter('merchantId', $merchant->getId(), 'uuid');
        $this->applyFilters($queryBuilder, $criteria);

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(transaction)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $queryBuilder
            ->orderBy('transaction.createdAt', 'DESC')
            ->addOrderBy('transaction.id', 'DESC')
            ->setFirstResult(($criteria->page - 1) * $criteria->perPage)
            ->setMaxResults($criteria->perPage)
            ->getQuery()
            ->getResult();

        return new TransactionPage($this->onlyTransactions($rows), $total);
    }

    private function applyFilters(QueryBuilder $queryBuilder, TransactionListCriteria $criteria): void
    {
        if (null !== $criteria->status) {
            $queryBuilder
                ->andWhere('transaction.status = :status')
                ->setParameter('status', $criteria->status);
        }
        if (null !== $criteria->createdFrom) {
            $queryBuilder
                ->andWhere('transaction.createdAt >= :createdFrom')
                ->setParameter('createdFrom', $criteria->createdFrom);
        }
        if (null !== $criteria->createdTo) {
            $queryBuilder
                ->andWhere('transaction.createdAt <= :createdTo')
                ->setParameter('createdTo', $criteria->createdTo);
        }
        if (null !== $criteria->amountMin) {
            $queryBuilder
                ->andWhere('transaction.amount >= :amountMin')
                ->setParameter('amountMin', $criteria->amountMin);
        }
        if (null !== $criteria->amountMax) {
            $queryBuilder
                ->andWhere('transaction.amount <= :amountMax')
                ->setParameter('amountMax', $criteria->amountMax);
        }
        if (null !== $criteria->query && '' !== $criteria->query) {
            $prefix = addcslashes($criteria->query, '%_\\').'%';
            $queryBuilder
                ->andWhere('transaction.merchantReference LIKE :referencePrefix OR transaction.phone = :phone')
                ->setParameter('referencePrefix', $prefix)
                ->setParameter('phone', $criteria->query);
        }
    }

    private function one(QueryBuilder $queryBuilder): ?Transaction
    {
        $transaction = $queryBuilder->getQuery()->getOneOrNullResult();

        return $transaction instanceof Transaction ? $transaction : null;
    }

    /**
     * @return list<Transaction>
     */
    private function onlyTransactions(mixed $rows): array
    {
        if (!\is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if ($row instanceof Transaction) {
                $items[] = $row;
            }
        }

        return $items;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Transaction::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Gestionnaire de persistance indisponible.');
        }

        return $manager;
    }
}
