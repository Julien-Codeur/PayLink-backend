<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Domain\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class RefreshTokenIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function issue(Merchant $merchant): string
    {
        $plain = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $expiresAt = $now->modify('+7 days');
        $this->entityManager->persist(new RefreshToken($merchant, hash('sha256', $plain), $expiresAt, $now));

        return $plain;
    }
}
