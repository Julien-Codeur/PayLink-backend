<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Entity\TransactionEvent;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\ProcessPayment;
use App\Payment\Domain\Service\SettlePayment;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Messenger\ProcessPaymentHandler;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Tests\Functional\Payment\PaymentTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ProcessPaymentHandlerTest extends PaymentTestCase
{
    public function testLeHandlerPasseEnPendingPuisIgnoreLeRejeu(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'MSG-1'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->handler()(new ProcessPayment($id));

        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Pending, $transaction->getStatus());
        $events = $this->events($transaction);
        self::assertCount(1, $events);
        self::assertSame(TransactionStatus::Initiated, $events[0]->getFromStatus());
        self::assertSame(TransactionStatus::Pending, $events[0]->getToStatus());
        self::assertSame(TransitionReason::START, $events[0]->getReason());

        $this->handler()(new ProcessPayment($id));

        $again = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Pending, $again->getStatus());
        self::assertCount(1, $this->events($again));
    }

    public function testUnRejeuSurUnStatutFinalNeChangeRien(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $successId = $this->create($client, $merchant['api_key'], 'MSG-OK', '+22890000012');
        $failedId = $this->create($client, $merchant['api_key'], 'MSG-KO', '+22890000010');
        $this->handler()(new ProcessPayment($successId));
        $this->handler()(new ProcessPayment($failedId));
        $this->settle()->callback(Uuid::fromString($successId), 'succeed', null);
        $this->settle()->callback(Uuid::fromString($failedId), 'fail', FailureCode::InsufficientFunds->value);

        $successEvents = \count($this->events($this->load($merchant['email'], $successId)));
        $failedEvents = \count($this->events($this->load($merchant['email'], $failedId)));
        $this->handler()(new ProcessPayment($successId));
        $this->handler()(new ProcessPayment($failedId));

        $success = $this->load($merchant['email'], $successId);
        $failed = $this->load($merchant['email'], $failedId);
        self::assertSame(TransactionStatus::Success, $success->getStatus());
        self::assertSame(TransactionStatus::Failed, $failed->getStatus());
        self::assertCount($successEvents, $this->events($success));
        self::assertCount($failedEvents, $this->events($failed));
    }

    public function testLaCreationPublieLeMessageEtLeRejeuNon(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'MSG-PUB'), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');
        $published = $this->published();
        self::assertCount(1, $published);
        self::assertSame($id, $published[0]->transactionId);

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'MSG-PUB'), $key);
        self::assertSame('true', $client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame([], $this->published());
    }

    private function handler(): ProcessPaymentHandler
    {
        $handler = static::getContainer()->get(ProcessPaymentHandler::class);
        self::assertInstanceOf(ProcessPaymentHandler::class, $handler);

        return $handler;
    }

    private function settle(): SettlePayment
    {
        $settle = static::getContainer()->get(SettlePayment::class);
        self::assertInstanceOf(SettlePayment::class, $settle);

        return $settle;
    }

    private function create(KernelBrowser $client, string $apiKey, string $reference, string $phone): string
    {
        $this->postTransaction($client, $apiKey, $this->paymentBody(reference: $reference, phone: $phone), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return $this->stringValue($this->decode($client), 'id');
    }

    private function load(string $email, string $id): Transaction
    {
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($email), $id);
        self::assertInstanceOf(Transaction::class, $transaction);

        return $transaction;
    }

    /**
     * @return list<TransactionEvent>
     */
    private function events(Transaction $transaction): array
    {
        $events = static::getContainer()->get(TransactionEventRepository::class)->findForTransaction($transaction);
        self::assertIsArray($events);

        return $events;
    }

    /**
     * @return list<ProcessPayment>
     */
    private function published(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ProcessPayment) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
