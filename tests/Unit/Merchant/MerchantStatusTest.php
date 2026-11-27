<?php

declare(strict_types=1);

namespace App\Tests\Unit\Merchant;

use App\Merchant\Domain\Enum\MerchantStatus;
use PHPUnit\Framework\TestCase;

final class MerchantStatusTest extends TestCase
{
    public function testSeulsActiveEtSuspendedSontAcceptes(): void
    {
        self::assertSame('ACTIVE', MerchantStatus::Active->value); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('SUSPENDED', MerchantStatus::Suspended->value); // @phpstan-ignore staticMethod.alreadyNarrowedType
        $this->expectException(\ValueError::class);
        MerchantStatus::from('BANNED');
    }
}
