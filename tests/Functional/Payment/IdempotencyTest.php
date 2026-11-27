<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\ProviderCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Model\CreateTransactionCommand;
use App\Payment\Domain\Service\CreateTransaction;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\ApiProblemResponse;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class IdempotencyTest extends PaymentTestCase
{
    use ClockSensitiveTrait;

    public function testLEnTeteEstObligatoireEtBorne(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $before = $this->transactionTotal($merchant['email']);

        $this->request($client, 'POST', '/api/v1/transactions', $this->paymentBody(), [
            'X-API-Key' => $merchant['api_key'],
        ]);
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $this->idempotencyKey(15));
        $this->assertProblem($client, 400, '/errors/validation');
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), str_repeat('k', 65));
        $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame($before, $this->transactionTotal($merchant['email']));

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'CLE-16'), $this->idempotencyKey(16));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: 'CLE-64'), $this->idempotencyKey(64));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testLaMemeCleEtLeMemeCorpsRejouentLaReponse(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();
        $body = $this->paymentBody();

        $this->postTransaction($client, $merchant['api_key'], $body, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('Idempotent-Replayed'));
        $first = (string) $client->getResponse()->getContent();

        $this->postTransaction($client, $merchant['api_key'], $body, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('true', $client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame($first, (string) $client->getResponse()->getContent());
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($merchant['email']), $key));

        $client->request(
            'POST',
            '/api/v1/transactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_API_KEY' => $merchant['api_key'],
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ],
            content: '{"merchant_reference":"CMD-2026-0042","provider":"MTN_SIM","phone":"+22890123452","amount":5000}',
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($first, (string) $client->getResponse()->getContent());
        self::assertSame('true', $client->getResponse()->headers->get('Idempotent-Replayed'));
    }

    public function testLaMemeCleEtUnAutreCorpsRenvoient409(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(1500), $key);
        $this->assertProblem($client, 409, '/errors/idempotency-conflict');
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($merchant['email']), $key));

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame(5000, $this->decode($client)['amount']);
        self::assertSame('INITIATED', $this->decode($client)['status']);
    }

    public function testLaCleEstPropreAChaqueMarchand(): void
    {
        $client = static::createClient();
        $first = $this->openMerchant($client, 'Boutique A');
        $second = $this->openMerchant($client, 'Boutique B');
        $key = $this->idempotencyKey();
        $body = $this->paymentBody();

        $this->postTransaction($client, $first['api_key'], $body, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $firstId = $this->stringValue($this->decode($client), 'id');

        $this->postTransaction($client, $second['api_key'], $body, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $secondId = $this->stringValue($this->decode($client), 'id');

        self::assertNotSame($firstId, $secondId);
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($first['email']), $key));
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($second['email']), $key));
    }

    public function testUnRefus400NeConsommePasLaCle(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(99), $key);
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('Idempotent-Replayed'));
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($merchant['email']), $key));
    }

    public function testLeRejeuResteLaReponseFigeeApresUnChangementDeStatut(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $frozen = (string) $client->getResponse()->getContent();
        $id = $this->stringValue($this->decode($client), 'id');

        $owner = $this->merchant($merchant['email']);
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($owner, $id);
        self::assertInstanceOf(Transaction::class, $transaction);
        $lifecycle = static::getContainer()->get(TransactionLifecycle::class);
        self::assertInstanceOf(TransactionLifecycle::class, $lifecycle);
        $lifecycle->apply($transaction, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($transaction, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('true', $client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame($frozen, (string) $client->getResponse()->getContent());

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame('SUCCESS', $this->decode($client)['status']);
    }

    public function testLeRejeuFonctionneEncoreApres23Heures(): void
    {
        self::mockTime('2026-11-21 08:00:00');
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $frozen = (string) $client->getResponse()->getContent();

        self::mockTime('2026-11-22 07:00:00');
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('true', $client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame($frozen, (string) $client->getResponse()->getContent());
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($merchant['email']), $key));
    }

    public function testLeConflitDInsertionRelitLaLigne(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $frozen = (string) $client->getResponse()->getContent();

        $owner = $this->merchant($merchant['email']);
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneByMerchantAndKey($owner, $key);
        self::assertInstanceOf(Transaction::class, $transaction);
        $service = static::getContainer()->get(CreateTransaction::class);
        self::assertInstanceOf(CreateTransaction::class, $service);
        $method = new \ReflectionMethod(CreateTransaction::class, 'replayAfterConflict');
        $same = new CreateTransactionCommand(5000, '+22890123452', ProviderCode::MtnSim, 'CMD-2026-0042', null, $key, $transaction->getRequestHash());
        $replay = $method->invoke($service, $owner->getId(), $same);
        self::assertInstanceOf(\App\Payment\Domain\Model\TransactionResult::class, $replay);
        self::assertTrue($replay->replayed);
        self::assertSame($frozen, $replay->body);
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($owner, $key));

        $different = new CreateTransactionCommand(1500, '+22890123452', ProviderCode::MtnSim, 'CMD-2026-0042', null, $key, hash('sha256', 'autre'));
        try {
            $method->invoke($service, $owner->getId(), $different);
            self::fail('Un contenu différent aurait dû produire un conflit.');
        } catch (ApiProblemException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertSame('/errors/idempotency-conflict', $exception->type);
        }
        self::assertSame(TransactionStatus::Initiated, $transaction->getStatus());
    }

    public function testUneLigneAbsenteApresConflitRepond503(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $service = static::getContainer()->get(CreateTransaction::class);
        self::assertInstanceOf(CreateTransaction::class, $service);
        $method = new \ReflectionMethod(CreateTransaction::class, 'replayAfterConflict');
        $missing = new CreateTransactionCommand(
            5000,
            '+22890123452',
            ProviderCode::MtnSim,
            'CMD-2026-0042',
            null,
            $this->idempotencyKey(),
            hash('sha256', 'absent'),
        );

        try {
            $method->invoke($service, $this->merchant($merchant['email'])->getId(), $missing);
            self::fail('Une ligne absente aurait dû être indisponible.');
        } catch (ApiProblemException $exception) {
            self::assertSame(503, $exception->statusCode);
            self::assertSame('/errors/unavailable', $exception->type);
            self::assertSame(1, $exception->retryAfter);
            $response = new ApiProblemResponse($exception);
            self::assertSame('1', $response->headers->get('Retry-After'));
        }
    }
}
