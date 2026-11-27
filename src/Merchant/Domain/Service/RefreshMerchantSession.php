<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Model\AuthTokens;
use App\Merchant\Infrastructure\Repository\RefreshTokenRepository;
use App\Shared\Http\ApiProblemException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;

final class RefreshMerchantSession
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly RefreshTokenIssuer $issuer,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(string $plainToken): AuthTokens
    {
        $stored = $this->refreshTokens->findOneByHash(hash('sha256', $plainToken));
        if (null === $stored || $stored->isExpired($this->clock->now())) {
            if (null !== $stored) {
                $this->entityManager->remove($stored);
                $this->entityManager->flush();
            }

            throw ApiProblemException::authentication('Refresh token invalide.');
        }

        $merchant = $stored->getMerchant();
        $this->entityManager->remove($stored);
        $tokens = new AuthTokens($this->jwt->create($merchant), $this->issuer->issue($merchant));
        $this->entityManager->flush();

        return $tokens;
    }
}
