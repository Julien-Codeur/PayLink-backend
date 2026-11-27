<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\ExpirePayment;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Messenger\ExpirePaymentHandler;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ExpirePaymentHandlerTest extends PaymentTestCase
{
    use ClockSensitiveTrait;

    public function testUneExpirationPrematureeNeChangeRien(): void
    {
        self::mockTime('2026-06-01 12:00:00');
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'EXPIRE-1'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');
        $transaction = $this->load($merchant['email'], $id);
        $lifecycle = static::getContainer()->get(TransactionLifecycle::class);
        self::assertInstanceOf(TransactionLifecycle::class, $lifecycle);
        $lifecycle->apply($transaction, TransactionTransition::START, TransitionReason::START);

        self::mockTime('2026-06-01 12:00:59');
        $this->handler()(new ExpirePayment($id));
        $stillPending = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Pending, $stillPending->getStatus());
        self::assertNull($stillPending->getFailureReason());
        self::assertCount(1, $this->events($stillPending));

        self::mockTime('2026-06-01 12:01:00');
        $this->handler()(new ExpirePayment($id));
        $failed = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Failed, $failed->getStatus());
        self::assertSame(FailureCode::Timeout->value, $failed->getFailureReason());
        $events = $this->events($failed);
        self::assertCount(2, $events);
        self::assertSame(TransitionReason::TIMEOUT, $events[1]->getReason());
    }

    private function handler(): ExpirePaymentHandler
    {
        $handler = static::getContainer()->get(ExpirePaymentHandler::class);
        self::assertInstanceOf(ExpirePaymentHandler::class, $handler);

        return $handler;
    }

    private function load(string $email, string $id): Transaction
    {
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($email), $id);
        self::assertInstanceOf(Transaction::class, $transaction);

        return $transaction;
    }

    /**
     * @return list<\App\Payment\Domain\Entity\TransactionEvent>
     */
    private function events(Transaction $transaction): array
    {
        return static::getContainer()->get(TransactionEventRepository::class)->findForTransaction($transaction);
    }
}
