<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Repository;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Entity\TransactionEvent;
use App\Payment\Domain\Enum\TransactionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TransactionEvent> */
final class TransactionEventRepository extends ServiceEntityRepository
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
        parent::__construct($registry, TransactionEvent::class);
    }

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->manager()->createQueryBuilder()
            ->select($alias)
            ->from(TransactionEvent::class, $alias, $indexBy);
    }

    /**
     * @return list<TransactionEvent>
     */
    public function findForTransaction(Transaction $transaction): array
    {
        $rows = $this->createQueryBuilder('event')
            ->andWhere('IDENTITY(event.transaction) = :transactionId')
            ->setParameter('transactionId', $transaction->getId(), 'uuid')
            ->orderBy('event.createdAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();

        if (!\is_array($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            if ($row instanceof TransactionEvent) {
                $events[] = $row;
            }
        }

        return $events;
    }

    public function pendingAt(Transaction $transaction): ?\DateTimeImmutable
    {
        $event = $this->createQueryBuilder('event')
            ->andWhere('IDENTITY(event.transaction) = :transactionId')
            ->andWhere('event.toStatus = :pending')
            ->setParameter('transactionId', $transaction->getId(), 'uuid')
            ->setParameter('pending', TransactionStatus::Pending)
            ->orderBy('event.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $event instanceof TransactionEvent ? $event->getCreatedAt() : null;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(TransactionEvent::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Gestionnaire de persistance indisponible.');
        }

        return $manager;
    }
}
