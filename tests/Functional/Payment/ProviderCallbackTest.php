<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Messenger\ExpirePaymentHandler;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ProviderCallbackTest extends PaymentTestCase
{
    public function testUnSecretAbsentOuFauxEstRefuse(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->pending($client, $merchant['api_key'], $merchant['email']);

        $this->postCallback($client, $id, 'succeed', null, null);
        $this->assertProblem($client, 401, '/errors/authentication');
        self::assertSame(TransactionStatus::Pending, $this->load($merchant['email'], $id)->getStatus());

        $this->postCallback($client, $id, 'succeed', null, 'mauvais-secret');
        $this->assertProblem($client, 401, '/errors/authentication');
        self::assertSame(TransactionStatus::Pending, $this->load($merchant['email'], $id)->getStatus());
    }

    public function testUnSuccesPuisUnSecondCallbackLaissentLeStatutFinal(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->pending($client, $merchant['api_key'], $merchant['email']);

        $this->postCallback($client, $id, 'succeed', null, 'test_provider_secret');
        self::assertSame(204, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Success, $transaction->getStatus());
        $events = $this->events($transaction);
        self::assertCount(2, $events);
        self::assertSame(TransactionStatus::Pending, $events[1]->getFromStatus());
        self::assertSame(TransactionStatus::Success, $events[1]->getToStatus());
        self::assertSame(TransitionReason::SUCCEED, $events[1]->getReason());

        $this->postCallback($client, $id, 'succeed', null, 'test_provider_secret');
        self::assertSame(204, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $again = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Success, $again->getStatus());
        self::assertCount(2, $this->events($again));
    }

    public function testUnEchecEnregistreLaCause(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->pending($client, $merchant['api_key'], $merchant['email']);

        $this->postCallback($client, $id, 'fail', FailureCode::InsufficientFunds->value, 'test_provider_secret');
        self::assertSame(204, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Failed, $transaction->getStatus());
        self::assertSame(FailureCode::InsufficientFunds->value, $transaction->getFailureReason());
        $events = $this->events($transaction);
        self::assertCount(2, $events);
        self::assertSame(TransitionReason::FAIL, $events[1]->getReason());
    }

    public function testLExpirationSansCallbackEchouePourDelai(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->pending($client, $merchant['api_key'], $merchant['email']);

        $handler = static::getContainer()->get(ExpirePaymentHandler::class);
        self::assertInstanceOf(ExpirePaymentHandler::class, $handler);
        $handler(new \App\Payment\Domain\Message\ExpirePayment($id));

        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Failed, $transaction->getStatus());
        self::assertSame(FailureCode::Timeout->value, $transaction->getFailureReason());
        $events = $this->events($transaction);
        self::assertCount(2, $events);
        self::assertSame(TransactionStatus::Pending, $events[1]->getFromStatus());
        self::assertSame(TransactionStatus::Failed, $events[1]->getToStatus());
        self::assertSame(TransitionReason::TIMEOUT, $events[1]->getReason());
    }

    public function testUnSuccesSurUneTransactionInitieeEstRefuse(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'INIT-1'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->postCallback($client, $id, 'succeed', null, 'test_provider_secret');
        $this->assertProblem($client, 422, '/errors/invalid-transition');
        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Initiated, $transaction->getStatus());
        self::assertSame([], $this->events($transaction));
    }

    private function pending(KernelBrowser $client, string $apiKey, string $email): string
    {
        $this->postTransaction($client, $apiKey, $this->paymentBody(reference: 'CB-'.substr($this->idempotencyKey(), 0, 8)), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');
        $transaction = $this->load($email, $id);
        $lifecycle = static::getContainer()->get(\App\Payment\Domain\Service\TransactionLifecycle::class);
        self::assertInstanceOf(\App\Payment\Domain\Service\TransactionLifecycle::class, $lifecycle);
        $lifecycle->apply($transaction, \App\Payment\Domain\Service\TransactionTransition::START, TransitionReason::START);

        return $id;
    }

    private function postCallback(KernelBrowser $client, string $id, string $outcome, ?string $failureCode, ?string $secret): void
    {
        $headers = [];
        if (null !== $secret) {
            $headers['X-Provider-Secret'] = $secret;
        }
        $body = [
            'transaction_id' => $id,
            'outcome' => $outcome,
        ];
        if (null !== $failureCode) {
            $body['failure_reason'] = $failureCode;
        }
        $this->request($client, 'POST', '/internal/provider/callback', $body, $headers);
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
