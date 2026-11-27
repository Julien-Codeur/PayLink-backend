<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Model;

final readonly class UpdateMerchantProfileData
{
    public function __construct(
        public ?string $name,
        public ?string $password,
        public ?string $currentPassword,
    ) {
    }
}
