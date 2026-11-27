<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Security;

use App\Merchant\Domain\Entity\Merchant;
use App\Shared\Http\ApiProblemException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class ApiKeyMerchant
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function merchant(): Merchant
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$token instanceof PostAuthenticationToken || $token instanceof JWTPostAuthenticationToken || !$user instanceof Merchant) {
            throw ApiProblemException::authentication('Une clé API est requise.');
        }

        return $user;
    }
}
