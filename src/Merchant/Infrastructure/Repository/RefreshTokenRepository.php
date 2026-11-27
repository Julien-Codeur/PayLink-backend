<?php

declare(strict_types=1);

namespace App\Merchant\Infrastructure\Repository;

use App\Merchant\Domain\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RefreshToken> */
final class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findOneByHash(string $hash): ?RefreshToken
    {
        $token = $this->findOneBy(['tokenHash' => $hash]);

        return $token instanceof RefreshToken ? $token : null;
    }
}
