<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Domain\Entity\Merchant;

final class MerchantPresenter
{
    /**
     * @return array{id: string, name: string, email: string, status: string, created_at: string}
     */
    public function merchant(Merchant $merchant): array
    {
        return [
            'id' => (string) $merchant->getId(),
            'name' => $merchant->getName(),
            'email' => $merchant->getEmail(),
            'status' => $merchant->getStatus()->value,
            'created_at' => $merchant->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function apiKey(ApiKey $apiKey, ?string $plainKey = null): array
    {
        $payload = [
            'id' => (string) $apiKey->getId(),
            'prefix' => $apiKey->getPrefix(),
            'active' => $apiKey->isActive(),
            'last_used_at' => $apiKey->getLastUsedAt()?->format(\DATE_ATOM),
            'created_at' => $apiKey->getCreatedAt()->format(\DATE_ATOM),
        ];
        if (null !== $plainKey) {
            $payload['api_key'] = $plainKey;
        }

        return $payload;
    }
}
