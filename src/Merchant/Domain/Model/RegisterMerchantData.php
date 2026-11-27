<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Model;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterMerchantData
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
        #[Assert\Length(max: 255, maxMessage: 'Le nom est trop long.')]
        public string $name,
        #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        #[Assert\Length(max: 180)]
        public string $email,
        #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
        #[Assert\Length(min: 10, minMessage: 'Le mot de passe doit contenir au moins 10 caractères.', max: 255)]
        public string $password,
    ) {
    }
}
