<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Service\RefundDecision;
use App\Payment\Domain\Service\RefundPolicy;
use PHPUnit\Framework\TestCase;

final class RefundPolicyTest extends TestCase
{
    private RefundPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RefundPolicy();
    }

    public function testUnMontantPartielEstAccepte(): void
    {
        self::assertSame(
            RefundDecision::Partial,
            $this->policy->decide(TransactionStatus::Success, 5000, 0, 2000),
        );
    }

    public function testLeMontantEntierEnUneFoisEstTotal(): void
    {
        self::assertSame(
            RefundDecision::Full,
            $this->policy->decide(TransactionStatus::Success, 5000, 0, 5000),
        );
    }

    public function testLeSecondPartielQuiEpuiseLeRestantEstTotal(): void
    {
        self::assertSame(
            RefundDecision::Full,
            $this->policy->decide(TransactionStatus::PartiallyRefunded, 5000, 2000, 3000),
        );
    }

    public function testUnMontantNulEstRefuse(): void
    {
        self::assertSame(
            RefundDecision::InvalidAmount,
            $this->policy->decide(TransactionStatus::Success, 5000, 0, 0),
        );
    }

    public function testUnDepassementEstRefuseEtLaSommeAccepteeResteInchangee(): void
    {
        self::assertSame(
            RefundDecision::ExceedsBalance,
            $this->policy->decide(TransactionStatus::Success, 5000, 0, 5001),
        );
        self::assertSame(
            RefundDecision::ExceedsBalance,
            $this->policy->decide(TransactionStatus::PartiallyRefunded, 5000, 2000, 3001),
        );
        self::assertSame(
            RefundDecision::Full,
            $this->policy->decide(TransactionStatus::PartiallyRefunded, 5000, 2000, 3000),
        );
    }

    public function testUnStatutNonRemboursableEstRefuseAvantLeMontant(): void
    {
        self::assertSame(
            RefundDecision::NotAllowed,
            $this->policy->decide(TransactionStatus::Failed, 5000, 0, 1000),
        );
        self::assertSame(
            RefundDecision::NotAllowed,
            $this->policy->decide(TransactionStatus::Refunded, 5000, 5000, 1),
        );
    }
}
