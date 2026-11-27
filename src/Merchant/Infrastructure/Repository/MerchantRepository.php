<?php

declare(strict_types=1);

namespace App\Merchant\Infrastructure\Repository;

use App\Merchant\Domain\Entity\Merchant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Merchant> */
final class MerchantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Merchant::class);
    }

    public function findOneByEmail(string $email): ?Merchant
    {
        $merchant = $this->findOneBy(['email' => $email]);

        return $merchant instanceof Merchant ? $merchant : null;
    }
}
