<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Model;

final readonly class AuthTokens
{
    public function __construct(
        public string $token,
        public string $refreshToken,
    ) {
    }
}
