<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Entity\TransactionEvent;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\ApiProblemListener;
use App\Tests\Double\CollectingLogger;
use App\Tests\Functional\Payment\PaymentTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TransactionHistoryTest extends PaymentTestCase
{
    public function testChaqueChangementDeStatutEcritUnEvenement(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $successId = $this->createId($client, $merchant['api_key'], 'HIST-OK', '+22890123452');
        $failedId = $this->createId($client, $merchant['api_key'], 'HIST-KO', '+22890123453');
        $succeeded = $this->load($merchant['email'], $successId);
        $failed = $this->load($merchant['email'], $failedId);

        $lifecycle = $this->lifecycle();
        $lifecycle->apply($succeeded, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($succeeded, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);
        $lifecycle->apply($failed, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($failed, TransactionTransition::FAIL, TransitionReason::TIMEOUT);

        $successEvents = $this->events($succeeded);
        self::assertCount(2, $successEvents);
        self::assertSame(TransactionStatus::Initiated, $successEvents[0]->getFromStatus());
        self::assertSame(TransactionStatus::Pending, $successEvents[0]->getToStatus());
        self::assertSame(TransitionReason::START, $successEvents[0]->getReason());
        self::assertGreaterThan(2020, (int) $successEvents[0]->getCreatedAt()->format('Y'));
        self::assertSame(TransactionStatus::Success, $successEvents[1]->getToStatus());
        self::assertSame(TransitionReason::SUCCEED, $successEvents[1]->getReason());

        $failureEvents = $this->events($failed);
        self::assertCount(2, $failureEvents);
        self::assertSame(TransactionStatus::Failed, $failureEvents[1]->getToStatus());
        self::assertSame(TransitionReason::TIMEOUT, $failureEvents[1]->getReason());
        self::assertSame(TransitionReason::TIMEOUT, $failed->getFailureReason());

        $refundId = $this->createId($client, $merchant['api_key'], 'HIST-REMBOURSE', '+22890123454');
        $refunded = $this->load($merchant['email'], $refundId);
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($refunded, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($refunded, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);
        $refunded->prepareRefund(0, 1000);
        $lifecycle->apply($refunded, TransactionTransition::REFUND_PARTIAL, TransitionReason::REFUND_PARTIAL);
        $refunded->prepareRefund(1000, 4000);
        $lifecycle->apply($refunded, TransactionTransition::REFUND_FULL, TransitionReason::REFUND_FULL);
        $refundEvents = $this->events($refunded);
        self::assertCount(4, $refundEvents);
        self::assertSame(TransactionStatus::Success, $refundEvents[2]->getFromStatus());
        self::assertSame(TransactionStatus::PartiallyRefunded, $refundEvents[2]->getToStatus());
        self::assertSame(TransactionStatus::PartiallyRefunded, $refundEvents[3]->getFromStatus());
        self::assertSame(TransactionStatus::Refunded, $refundEvents[3]->getToStatus());
        self::assertSame(TransactionStatus::Refunded, $refunded->getStatus());
    }

    public function testRejouerLeDemarrageSurUnStatutFinalNeCreeRien(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $successId = $this->createId($client, $merchant['api_key'], 'FINAL-OK', '+22890123455');
        $failedId = $this->createId($client, $merchant['api_key'], 'FINAL-KO', '+22890123456');
        $succeeded = $this->load($merchant['email'], $successId);
        $failed = $this->load($merchant['email'], $failedId);
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($succeeded, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($succeeded, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);
        $lifecycle->apply($failed, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($failed, TransactionTransition::FAIL, TransitionReason::FAIL);

        $successCount = \count($this->events($succeeded));
        $failureCount = \count($this->events($failed));
        $lifecycle->startIfStillInitiated($succeeded);
        $lifecycle->startIfStillInitiated($failed);

        self::assertCount($successCount, $this->events($succeeded));
        self::assertCount($failureCount, $this->events($failed));
        self::assertSame(TransactionStatus::Success, $succeeded->getStatus());
        self::assertSame(TransactionStatus::Failed, $failed->getStatus());
    }

    public function testUnSecondRemboursementPartielNeCreePasDEvenement(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->createId($client, $merchant['api_key'], 'PARTIEL-2', '+22890123457');
        $transaction = $this->load($merchant['email'], $id);
        $lifecycle = $this->lifecycle();
        $lifecycle->apply($transaction, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($transaction, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);
        $transaction->prepareRefund(0, 1000);
        $lifecycle->apply($transaction, TransactionTransition::REFUND_PARTIAL, TransitionReason::REFUND_PARTIAL);
        $count = \count($this->events($transaction));

        $transaction->prepareRefund(1000, 500);
        $lifecycle->apply($transaction, TransactionTransition::REFUND_PARTIAL, TransitionReason::REFUND_PARTIAL);

        self::assertSame(TransactionStatus::PartiallyRefunded, $transaction->getStatus());
        self::assertCount($count, $this->events($transaction));
    }

    public function testUneTransitionInterditeRepond422SansEvenementNiChangement(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->createId($client, $merchant['api_key'], 'INTERDIT', '+22890123458');
        $transaction = $this->load($merchant['email'], $id);
        $logger = static::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(CollectingLogger::class, $logger);
        $logger->records = [];

        try {
            $this->lifecycle()->apply($transaction, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);
            self::fail('La transition aurait dû être refusée.');
        } catch (ApiProblemException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame('/errors/invalid-transition', $exception->type);
            self::assertSame(TransactionStatus::Initiated, $transaction->getStatus());
            self::assertSame([], $this->events($transaction));
            self::assertNotSame([], $logger->records);
            self::assertSame('succeed', $logger->records[0]['context']['transition']);
            self::assertSame('INITIATED', $logger->records[0]['context']['from_status']);

            $kernel = self::$kernel;
            self::assertInstanceOf(HttpKernelInterface::class, $kernel);
            $event = new ExceptionEvent(
                $kernel,
                Request::create('/api/v1/transactions/'.$transaction->getId()),
                HttpKernelInterface::MAIN_REQUEST,
                $exception,
            );
            $listener = static::getContainer()->get(ApiProblemListener::class);
            self::assertInstanceOf(ApiProblemListener::class, $listener);
            $listener($event);
            $response = $event->getResponse();
            self::assertNotNull($response);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('application/problem+json', (string) $response->headers->get('content-type'));
            $payload = json_decode((string) $response->getContent(), true);
            self::assertIsArray($payload);
            self::assertSame('/errors/invalid-transition', $payload['type']);
            self::assertSame(422, $payload['status']);
        }
    }

    private function createId(KernelBrowser $client, string $apiKey, string $reference, string $phone): string
    {
        $this->postTransaction($client, $apiKey, $this->paymentBody(5000, $reference, $phone), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return $this->stringValue($this->decode($client), 'id');
    }

    private function load(string $email, string $id): Transaction
    {
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($email), $id);
        self::assertInstanceOf(Transaction::class, $transaction);

        return $transaction;
    }

    private function lifecycle(): TransactionLifecycle
    {
        $lifecycle = static::getContainer()->get(TransactionLifecycle::class);
        self::assertInstanceOf(TransactionLifecycle::class, $lifecycle);

        return $lifecycle;
    }

    /**
     * @return list<TransactionEvent>
     */
    private function events(Transaction $transaction): array
    {
        return static::getContainer()->get(TransactionEventRepository::class)->findForTransaction($transaction);
    }
}
