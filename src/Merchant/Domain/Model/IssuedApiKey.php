<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Model;

use App\Merchant\Domain\Entity\ApiKey;

final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        public string $plainKey,
    ) {
    }
}
