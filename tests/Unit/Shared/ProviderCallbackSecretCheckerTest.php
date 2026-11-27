<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Security\ProviderCallbackSecretChecker;
use PHPUnit\Framework\TestCase;

final class ProviderCallbackSecretCheckerTest extends TestCase
{
    private ProviderCallbackSecretChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ProviderCallbackSecretChecker();
    }

    public function testUnSecretCourtOuPlaceholderEstRefuseHorsDev(): void
    {
        $this->expectException(\LogicException::class);
        $this->checker->assertUsable('', 'prod');
    }

    public function testLePlaceholderEstRefuseEnProduction(): void
    {
        $this->expectException(\LogicException::class);
        $this->checker->assertUsable(ProviderCallbackSecretChecker::PLACEHOLDER, 'prod');
    }

    public function testUnSecretDe31CaracteresEstRefuse(): void
    {
        $this->expectException(\LogicException::class);
        $this->checker->assertUsable(str_repeat('a', 31), 'prod');
    }

    public function testUnSecretDe32CaracteresEstAccepteEnProduction(): void
    {
        $this->checker->assertUsable(str_repeat('b', 32), 'prod');
        $this->addToAssertionCount(1);
    }

    public function testLeDeveloppementEtLesTestsGardentLePlaceholder(): void
    {
        $this->checker->assertUsable(ProviderCallbackSecretChecker::PLACEHOLDER, 'dev');
        $this->checker->assertUsable('court', 'test');
        $this->addToAssertionCount(1);
    }
}
