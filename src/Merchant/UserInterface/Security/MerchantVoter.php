<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Security;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Domain\Entity\Merchant;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, ApiKey> */
final class MerchantVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const REVOKE = 'REVOKE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ApiKey && \in_array($attribute, [self::VIEW, self::REVOKE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Merchant || !$subject instanceof ApiKey) { // @phpstan-ignore instanceof.alwaysTrue
            return false;
        }

        return $user->getId()->equals($subject->getMerchant()->getId());
    }
}
