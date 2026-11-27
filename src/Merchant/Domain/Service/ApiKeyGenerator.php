<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Model\GeneratedApiKey;

final class ApiKeyGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(): GeneratedApiKey
    {
        $random = '';
        $last = \strlen(self::ALPHABET) - 1;
        for ($index = 0; $index < 32; ++$index) {
            $random .= self::ALPHABET[random_int(0, $last)];
        }

        $plainKey = 'pl_test_'.$random;

        return new GeneratedApiKey($plainKey, substr($random, 0, 8), hash('sha256', $plainKey));
    }
}
