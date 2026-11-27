<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\ProcessPayment;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Cli\RequeueStuckPaymentsCommand;
use App\Payment\Infrastructure\Messenger\ProcessPaymentHandler;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RequeueStuckPaymentsTest extends PaymentTestCase
{
    use ClockSensitiveTrait;

    public function testUnPaiementInitieTropLongtempsEstRemisEnFileUneSeuleFois(): void
    {
        self::mockTime('2026-04-01 00:00:00');
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'STUCK-INIT'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        self::mockTime('2026-04-01 00:00:29');
        $this->requeue();
        self::assertCount(1, $this->messagesFor($id));

        self::mockTime('2026-04-01 00:00:30');
        $this->requeue();
        $messages = $this->messagesFor($id);
        self::assertCount(2, $messages);

        $handler = static::getContainer()->get(ProcessPaymentHandler::class);
        self::assertInstanceOf(ProcessPaymentHandler::class, $handler);
        foreach ($messages as $message) {
            $handler($message);
        }

        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Pending, $transaction->getStatus());
        self::assertCount(1, $this->events($transaction));

        $this->requeue();
        self::assertCount(2, $this->messagesFor($id));
        self::assertCount(1, $this->events($this->load($merchant['email'], $id)));
    }

    public function testUnPaiementEnAttenteEstExpireDepuisLEvenementPending(): void
    {
        self::mockTime('2026-03-01 00:00:00');
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'STUCK-PEND'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        self::mockTime('2026-03-01 00:00:40');
        $transaction = $this->load($merchant['email'], $id);
        $lifecycle = static::getContainer()->get(TransactionLifecycle::class);
        self::assertInstanceOf(TransactionLifecycle::class, $lifecycle);
        $lifecycle->apply($transaction, TransactionTransition::START, TransitionReason::START);

        self::mockTime('2026-03-01 00:01:49');
        $this->requeue();
        $stillPending = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Pending, $stillPending->getStatus());
        self::assertCount(1, $this->events($stillPending));

        self::mockTime('2026-03-01 00:01:50');
        $this->requeue();
        $failed = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Failed, $failed->getStatus());
        self::assertSame(FailureCode::Timeout->value, $failed->getFailureReason());
        $events = $this->events($failed);
        self::assertCount(2, $events);
        self::assertSame(TransitionReason::TIMEOUT, $events[1]->getReason());

        $this->requeue();
        self::assertCount(2, $this->events($this->load($merchant['email'], $id)));
    }

    private function requeue(): void
    {
        $command = static::getContainer()->get(RequeueStuckPaymentsCommand::class);
        self::assertInstanceOf(RequeueStuckPaymentsCommand::class, $command);
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        self::assertSame(0, $command->run($input, new NullOutput()));
    }

    /**
     * @return list<ProcessPayment>
     */
    private function messagesFor(string $id): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ProcessPayment && $message->transactionId === $id) {
                $messages[] = $message;
            }
        }

        return $messages;
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
