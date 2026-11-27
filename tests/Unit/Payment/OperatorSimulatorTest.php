<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Service\OperatorSimulator;
use PHPUnit\Framework\TestCase;

final class OperatorSimulatorTest extends TestCase
{
    private OperatorSimulator $simulator;

    protected function setUp(): void
    {
        $this->simulator = new OperatorSimulator();
    }

    public function testUnNumeroFiniParZeroEchoueSansAttente(): void
    {
        $decision = $this->simulator->decide('+22890000000');

        self::assertFalse($decision->success);
        self::assertTrue($decision->notifies);
        self::assertSame(0, $decision->delaySeconds);
        self::assertSame(FailureCode::InsufficientFunds->value, $decision->failureCode);
    }

    public function testUnNumeroFiniParUnCorrespondAuDelaiDepasse(): void
    {
        $decision = $this->simulator->decide('+22890000001');

        self::assertFalse($decision->success);
        self::assertFalse($decision->notifies);
        self::assertSame(0, $decision->delaySeconds);
        self::assertSame(FailureCode::Timeout->value, $decision->failureCode);
    }

    public function testUnNumeroFiniParNeufReussitApresDixSecondes(): void
    {
        $decision = $this->simulator->decide('+22890000009');

        self::assertTrue($decision->success);
        self::assertTrue($decision->notifies);
        self::assertSame(10, $decision->delaySeconds);
        self::assertNull($decision->failureCode);
    }

    public function testLesAutresNumerosReussissentApresDeuxSecondes(): void
    {
        foreach (['+22890000002', '+22890000005'] as $phone) {
            $decision = $this->simulator->decide($phone);

            self::assertTrue($decision->success, $phone);
            self::assertTrue($decision->notifies, $phone);
            self::assertSame(2, $decision->delaySeconds, $phone);
            self::assertNull($decision->failureCode, $phone);
        }
    }

    public function testLeMemeNumeroDonneToujoursLeMemeResultat(): void
    {
        $simulator = $this->simulator;

        self::assertEquals($simulator->decide('+22890123459'), $simulator->decide('+22890123459'));
        self::assertEquals($simulator->decide('+22890123450'), $simulator->decide('+22890123450'));
    }
}
