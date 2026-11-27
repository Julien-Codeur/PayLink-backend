<?php

declare(strict_types=1);

namespace App\Payment\UserInterface\Security;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Entity\Transaction;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, Transaction> */
final class TransactionVoter extends Voter
{
    public const VIEW = 'VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof Transaction;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Merchant || !$subject instanceof Transaction) { // @phpstan-ignore instanceof.alwaysTrue
            return false;
        }

        return $user->getId()->equals($subject->getMerchant()->getId());
    }
}
