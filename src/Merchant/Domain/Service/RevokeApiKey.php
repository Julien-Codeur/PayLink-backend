<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;

final class RevokeApiKey
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function execute(ApiKey $apiKey): void
    {
        $apiKey->revoke();
        $this->entityManager->flush();
    }
}
