<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Shared\Http\ApiProblemException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterMerchant
{
    public function __construct(
        private readonly MerchantRepository $merchants,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(string $name, string $email, string $password): Merchant
    {
        if ($this->merchants->findOneByEmail($email) instanceof Merchant) {
            throw ApiProblemException::validation('Cet e-mail est déjà utilisé.', '/email');
        }

        $merchant = Merchant::register($name, $email, '', $this->clock->now());
        $merchant->changePassword($this->passwordHasher->hashPassword($merchant, $password));
        $this->entityManager->persist($merchant);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw ApiProblemException::validation('Cet e-mail est déjà utilisé.', '/email');
        }

        return $merchant;
    }
}
