<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Security;

use App\Merchant\Domain\Entity\Merchant;
use App\Shared\Http\ApiProblemException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class JwtMerchant
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function merchant(): Merchant
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$token instanceof JWTPostAuthenticationToken || !$user instanceof Merchant) {
            throw ApiProblemException::authentication('Un jeton JWT est requis.');
        }

        return $user;
    }
}
