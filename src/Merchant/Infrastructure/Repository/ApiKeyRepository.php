<?php

declare(strict_types=1);

namespace App\Merchant\Infrastructure\Repository;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Domain\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ApiKey> */
final class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function findOneById(string $id): ?ApiKey
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $key = $this->find($uuid);

        return $key instanceof ApiKey ? $key : null;
    }

    public function findOneByHash(string $hash): ?ApiKey
    {
        $key = $this->findOneBy(['keyHash' => $hash]);

        return $key instanceof ApiKey ? $key : null;
    }

    public function findOneActiveByHash(string $hash): ?ApiKey
    {
        $key = $this->findOneBy(['keyHash' => $hash, 'active' => true]);

        return $key instanceof ApiKey ? $key : null;
    }

    public function countActiveFor(Merchant $merchant): int
    {
        return (int) $this->createQueryBuilder('apiKey')
            ->select('COUNT(apiKey)')
            ->andWhere('IDENTITY(apiKey.merchant) = :merchantId')
            ->andWhere('apiKey.active = true')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ApiKey>
     */
    public function findByMerchant(Merchant $merchant): array
    {
        $keys = $this->createQueryBuilder('apiKey')
            ->andWhere('IDENTITY(apiKey.merchant) = :merchantId')
            ->setParameter('merchantId', $merchant->getId(), 'uuid')
            ->orderBy('apiKey.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($keys as $key) {
            if ($key instanceof ApiKey) {
                $result[] = $key;
            }
        }

        return $result;
    }
}
