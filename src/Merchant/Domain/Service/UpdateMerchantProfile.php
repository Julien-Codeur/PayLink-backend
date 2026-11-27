<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Domain\Model\UpdateMerchantProfileData;
use App\Shared\Http\ApiProblemException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UpdateMerchantProfile
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function execute(Merchant $merchant, UpdateMerchantProfileData $data): Merchant
    {
        if (null === $data->name && null === $data->password) {
            throw ApiProblemException::validation('Aucune modification demandée.');
        }

        if (null !== $data->name) {
            $name = trim($data->name);
            if ('' === $name) {
                throw ApiProblemException::validation('Le nom est obligatoire.', '/name');
            }
            if (mb_strlen($name) > 255) {
                throw ApiProblemException::validation('Le nom est trop long.', '/name');
            }
            $merchant->rename($name);
        }

        if (null !== $data->password) {
            if (\strlen($data->password) < 10) {
                throw ApiProblemException::validation('Le mot de passe doit contenir au moins 10 caractères.', '/password');
            }
            if (\strlen($data->password) > 255) {
                throw ApiProblemException::validation('Le mot de passe est trop long.', '/password');
            }
            if (null === $data->currentPassword || '' === $data->currentPassword) {
                throw ApiProblemException::validation('Le mot de passe actuel est obligatoire.', '/current_password');
            }
            if (!$this->passwordHasher->isPasswordValid($merchant, $data->currentPassword)) {
                throw ApiProblemException::validation('Le mot de passe actuel est incorrect.', '/current_password');
            }
            $merchant->changePassword($this->passwordHasher->hashPassword($merchant, $data->password));
        }

        $this->entityManager->flush();

        return $merchant;
    }
}
