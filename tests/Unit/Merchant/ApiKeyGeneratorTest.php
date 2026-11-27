<?php

declare(strict_types=1);

namespace App\Tests\Unit\Merchant;

use App\Merchant\Domain\Service\ApiKeyGenerator;
use PHPUnit\Framework\TestCase;

final class ApiKeyGeneratorTest extends TestCase
{
    public function testDeuxGenerationsSuccessivesDifferent(): void
    {
        $generator = new ApiKeyGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertNotSame($first->plainKey, $second->plainKey);
        self::assertMatchesRegularExpression('/^pl_test_[A-Za-z0-9]{32}$/', $first->plainKey);
        self::assertSame(substr($first->plainKey, 8, 8), $first->prefix);
        self::assertSame(hash('sha256', $first->plainKey), $first->keyHash);
        self::assertSame(8, \strlen($first->prefix));
    }
}
