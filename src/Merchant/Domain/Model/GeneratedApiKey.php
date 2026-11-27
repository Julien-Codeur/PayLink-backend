<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Model;

final readonly class GeneratedApiKey
{
    public function __construct(
        public string $plainKey,
        public string $prefix,
        public string $keyHash,
    ) {
    }
}
