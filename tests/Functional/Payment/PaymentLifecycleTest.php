<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\DeliverProviderCallback;
use App\Payment\Domain\Message\ExpirePayment;
use App\Payment\Domain\Message\ProcessPayment;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Messenger\DeliverProviderCallbackHandler;
use App\Payment\Infrastructure\Messenger\ExpirePaymentHandler;
use App\Payment\Infrastructure\Messenger\ProcessPaymentHandler;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PaymentLifecycleTest extends PaymentTestCase
{
    public function testUnNumeroRapideAboutitAuSuccesSansActionDuClient(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'CYCLE-OK', phone: '+22890000002'),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->drain(0);
        self::assertSame(TransactionStatus::Pending, $this->load($merchant['email'], $id)->getStatus());

        $this->drain(2000);
        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Success, $transaction->getStatus());
        $events = $this->events($transaction);
        self::assertCount(2, $events);
        self::assertSame(TransactionStatus::Initiated, $events[0]->getFromStatus());
        self::assertSame(TransactionStatus::Pending, $events[0]->getToStatus());
        self::assertSame(TransitionReason::START, $events[0]->getReason());
        self::assertSame(TransactionStatus::Pending, $events[1]->getFromStatus());
        self::assertSame(TransactionStatus::Success, $events[1]->getToStatus());
        self::assertSame(TransitionReason::SUCCEED, $events[1]->getReason());
    }

    public function testUnNumeroFiniParZeroAboutitAUnSoldeInsuffisant(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'CYCLE-KO', phone: '+22890000000'),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->drain(0);
        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Failed, $transaction->getStatus());
        self::assertSame(FailureCode::InsufficientFunds->value, $transaction->getFailureReason());
        $events = $this->events($transaction);
        self::assertCount(2, $events);
        self::assertSame(TransitionReason::FAIL, $events[1]->getReason());
    }

    private function drain(int $elapsedMs): void
    {
        $transport = $this->transport();
        $done = [];
        do {
            $moved = false;
            foreach ($transport->getSent() as $envelope) {
                $stamp = $envelope->last(TransportMessageIdStamp::class);
                if (!$stamp instanceof TransportMessageIdStamp || isset($done[$stamp->getId()])) {
                    continue;
                }
                $delay = $envelope->last(DelayStamp::class);
                $wait = $delay instanceof DelayStamp ? $delay->getDelay() : 0;
                if ($wait > $elapsedMs) {
                    continue;
                }
                $done[$stamp->getId()] = true;
                $this->handle($envelope->getMessage());
                $transport->ack($envelope);
                $moved = true;
            }
        } while ($moved);
    }

    private function handle(object $message): void
    {
        if ($message instanceof ProcessPayment) {
            $this->service(ProcessPaymentHandler::class)($message);

            return;
        }
        if ($message instanceof ExpirePayment) {
            $this->service(ExpirePaymentHandler::class)($message);

            return;
        }
        if ($message instanceof DeliverProviderCallback) {
            $this->service(DeliverProviderCallbackHandler::class)($message);
        }
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
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
