<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\ProviderCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\UserInterface\Security\TransactionVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

final class PaymentWorkflowTest extends KernelTestCase
{
    public function testLeChangementDeStatutResteReserveAuWorkflow(): void
    {
        $method = new \ReflectionMethod(Transaction::class, 'moveTo');
        self::assertTrue($method->isPrivate());

        $transaction = $this->transaction();
        $this->workflow($transaction)->apply($transaction, 'start');
        self::assertSame(TransactionStatus::Pending, $transaction->getStatus());
    }

    public function testLesTransitionsAutoriseesAtteignentLeStatutAttendu(): void
    {
        $initiated = $this->transaction();
        $this->workflow($initiated)->apply($initiated, 'start');
        self::assertSame(TransactionStatus::Pending, $initiated->getStatus());

        $succeeded = $this->transaction();
        $this->workflow($succeeded)->apply($succeeded, 'start');
        $this->workflow($succeeded)->apply($succeeded, 'succeed');
        self::assertSame(TransactionStatus::Success, $succeeded->getStatus());

        $failed = $this->transaction();
        $this->workflow($failed)->apply($failed, 'start');
        $this->workflow($failed)->apply($failed, 'fail');
        self::assertSame(TransactionStatus::Failed, $failed->getStatus());

        $partial = $this->transaction();
        $this->workflow($partial)->apply($partial, 'start');
        $this->workflow($partial)->apply($partial, 'succeed');
        $partial->prepareRefund(0, 1000);
        $this->workflow($partial)->apply($partial, 'refund_partial');
        self::assertSame(TransactionStatus::PartiallyRefunded, $partial->getStatus());
        $partial->prepareRefund(1000, 4000);
        $this->workflow($partial)->apply($partial, 'refund_full');
        self::assertSame(TransactionStatus::Refunded, $partial->getStatus());

        $full = $this->transaction();
        $this->workflow($full)->apply($full, 'start');
        $this->workflow($full)->apply($full, 'succeed');
        $full->prepareRefund(0, 5000);
        $this->workflow($full)->apply($full, 'refund_full');
        self::assertSame(TransactionStatus::Refunded, $full->getStatus());
    }

    #[DataProvider('transitionsInterdites')]
    public function testUneTransitionInterditeEstRefusee(TransactionStatus $from, string $transition): void
    {
        $transaction = $this->reach($from);
        $workflow = $this->workflow($transaction);

        try {
            $workflow->apply($transaction, $transition);
            self::fail('La transition '.$transition.' aurait dû être refusée.');
        } catch (LogicException) {
            self::assertSame($from, $transaction->getStatus());
        }
    }

    public function testLeRemboursementTotalEstRefuseSiLaSommeDiffereDuMontant(): void
    {
        $transaction = $this->reach(TransactionStatus::Success);
        $transaction->prepareRefund(0, 100);

        try {
            $this->workflow($transaction)->apply($transaction, 'refund_full');
            self::fail('Le remboursement total aurait dû être refusé.');
        } catch (LogicException) {
            self::assertSame(TransactionStatus::Success, $transaction->getStatus());
        }
    }

    public function testLeRemboursementPartielEstRefuseQuandLaSommeEgaleLeMontant(): void
    {
        $transaction = $this->reach(TransactionStatus::Success);
        $transaction->prepareRefund(0, 5000);

        try {
            $this->workflow($transaction)->apply($transaction, 'refund_partial');
            self::fail('Le remboursement partiel aurait dû être refusé.');
        } catch (LogicException) {
            self::assertSame(TransactionStatus::Success, $transaction->getStatus());
        }
    }

    public function testLeVoterIsoleLaTransactionDunAutreMarchand(): void
    {
        $owner = Merchant::register('A', 'owner@example.test', 'hash', new \DateTimeImmutable());
        $other = Merchant::register('B', 'other@example.test', 'hash', new \DateTimeImmutable());
        $transaction = $this->transaction($owner);
        $voter = static::getContainer()->get(TransactionVoter::class);
        self::assertInstanceOf(TransactionVoter::class, $voter);

        $ownerToken = new PostAuthenticationToken($owner, 'api', $owner->getRoles());
        $otherToken = new PostAuthenticationToken($other, 'api', $other->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($ownerToken, $transaction, [TransactionVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($otherToken, $transaction, [TransactionVoter::VIEW]));
    }

    /**
     * @return iterable<string, array{TransactionStatus, string}>
     */
    public static function transitionsInterdites(): iterable
    {
        yield 'initiated success' => [TransactionStatus::Initiated, 'succeed'];
        yield 'initiated failed' => [TransactionStatus::Initiated, 'fail'];
        yield 'initiated refunded' => [TransactionStatus::Initiated, 'refund_full'];
        yield 'initiated partial' => [TransactionStatus::Initiated, 'refund_partial'];
        yield 'pending initiated' => [TransactionStatus::Pending, 'rewind'];
        yield 'pending refunded' => [TransactionStatus::Pending, 'refund_full'];
        yield 'pending partial' => [TransactionStatus::Pending, 'refund_partial'];
        yield 'success initiated' => [TransactionStatus::Success, 'rewind'];
        yield 'success pending' => [TransactionStatus::Success, 'start'];
        yield 'success failed' => [TransactionStatus::Success, 'fail'];
        yield 'failed initiated' => [TransactionStatus::Failed, 'rewind'];
        yield 'failed pending' => [TransactionStatus::Failed, 'start'];
        yield 'failed success' => [TransactionStatus::Failed, 'succeed'];
        yield 'failed refunded' => [TransactionStatus::Failed, 'refund_full'];
        yield 'failed partial' => [TransactionStatus::Failed, 'refund_partial'];
        yield 'partial initiated' => [TransactionStatus::PartiallyRefunded, 'rewind'];
        yield 'partial pending' => [TransactionStatus::PartiallyRefunded, 'start'];
        yield 'partial success' => [TransactionStatus::PartiallyRefunded, 'succeed'];
        yield 'partial failed' => [TransactionStatus::PartiallyRefunded, 'fail'];
        yield 'refunded initiated' => [TransactionStatus::Refunded, 'rewind'];
        yield 'refunded pending' => [TransactionStatus::Refunded, 'start'];
        yield 'refunded success' => [TransactionStatus::Refunded, 'succeed'];
        yield 'refunded failed' => [TransactionStatus::Refunded, 'fail'];
        yield 'refunded partial' => [TransactionStatus::Refunded, 'refund_partial'];
    }

    private function reach(TransactionStatus $status): Transaction
    {
        $transaction = $this->transaction();
        $workflow = $this->workflow($transaction);
        if (TransactionStatus::Initiated === $status) {
            return $transaction;
        }

        $workflow->apply($transaction, 'start');
        if (TransactionStatus::Pending === $status) {
            return $transaction;
        }
        if (TransactionStatus::Failed === $status) {
            $workflow->apply($transaction, 'fail');

            return $transaction;
        }

        $workflow->apply($transaction, 'succeed');
        if (TransactionStatus::Success === $status) {
            return $transaction;
        }

        $transaction->prepareRefund(0, 1000);
        $workflow->apply($transaction, 'refund_partial');
        if (TransactionStatus::PartiallyRefunded === $status) {
            return $transaction;
        }

        $transaction->prepareRefund(1000, 4000);
        $workflow->apply($transaction, 'refund_full');

        return $transaction;
    }

    private function transaction(?Merchant $merchant = null): Transaction
    {
        return Transaction::initiate(
            $merchant ?? Merchant::register('Boutique', 'boutique@example.test', 'hash', new \DateTimeImmutable()),
            5000,
            '+22890123452',
            ProviderCode::MtnSim,
            'CMD-1',
            null,
            'idempotency-key-16',
            hash('sha256', 'corps'),
            new \DateTimeImmutable(),
        );
    }

    private function workflow(Transaction $transaction): WorkflowInterface
    {
        $registry = static::getContainer()->get(Registry::class);
        self::assertInstanceOf(Registry::class, $registry);

        return $registry->get($transaction);
    }
}
