<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Security;

use App\Merchant\Domain\Entity\Merchant;
use App\Shared\Http\ApiProblemException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class CurrentMerchant
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function merchant(): Merchant
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof Merchant) {
            throw ApiProblemException::authentication();
        }

        return $user;
    }
}
