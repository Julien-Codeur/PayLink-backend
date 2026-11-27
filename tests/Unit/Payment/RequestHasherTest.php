<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\Service\RequestHasher;
use PHPUnit\Framework\TestCase;

final class RequestHasherTest extends TestCase
{
    public function testLOrdreDesClesNeChangePasLEmpreinte(): void
    {
        $hasher = new RequestHasher();
        $left = [
            'merchant_reference' => 'CMD-2026-0042',
            'provider' => 'MTN_SIM',
            'nested' => ['z' => 1, 'a' => ['b' => 2, 'a' => 3]],
            'amount' => 5000,
            'phone' => '+22890123452',
        ];
        $right = [
            'amount' => 5000,
            'phone' => '+22890123452',
            'nested' => ['a' => ['a' => 3, 'b' => 2], 'z' => 1],
            'provider' => 'MTN_SIM',
            'merchant_reference' => 'CMD-2026-0042',
        ];

        self::assertSame($hasher->hash($left), $hasher->hash($right));
        self::assertNotSame($hasher->hash($left), $hasher->hash(['amount' => 5001] + $left));
    }
}
