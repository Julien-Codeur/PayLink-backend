<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Domain\Model\AuthTokens;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Shared\Http\ApiProblemException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginMerchant
{
    public function __construct(
        private readonly MerchantRepository $merchants,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly RefreshTokenIssuer $refreshTokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function execute(string $email, string $password): AuthTokens
    {
        $merchant = $this->merchants->findOneByEmail($email);
        if (!$merchant instanceof Merchant || !$this->passwordHasher->isPasswordValid($merchant, $password)) {
            throw ApiProblemException::authentication('Identifiants invalides.');
        }

        $tokens = new AuthTokens($this->jwt->create($merchant), $this->refreshTokens->issue($merchant));
        $this->entityManager->flush();

        return $tokens;
    }
}
