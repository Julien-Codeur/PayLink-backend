<?php

declare(strict_types=1);

namespace App\Merchant\Infrastructure\Security;

use App\Merchant\Domain\Entity\Merchant;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class MerchantUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Merchant || !$user->isSuspended()) {
            return;
        }

        $exception = new CustomUserMessageAccountStatusException('Un marchand suspendu ne peut pas accéder à l\'API.');
        $exception->setUser($user);

        throw $exception;
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
