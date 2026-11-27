<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use Doctrine\ORM\EntityManagerInterface;

final class CreateTransactionTest extends PaymentTestCase
{
    public function testLaCreationRenvoieUneTransactionInitiated(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $this->idempotencyKey());

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('content-type'));
        self::assertFalse($client->getResponse()->headers->has('Idempotent-Replayed'));
        $body = $this->decode($client);
        self::assertSame('INITIATED', $body['status']);
        self::assertSame(5000, $body['amount']);
        self::assertSame('XOF', $body['currency']);
        self::assertSame('+22890123452', $body['phone']);
        self::assertSame('MTN_SIM', $body['provider']);
        self::assertSame('CMD-2026-0042', $body['merchant_reference']);
        self::assertNull($body['description']);
        self::assertIsString($body['id']);
        self::assertIsString($body['created_at']);
    }

    public function testLesBornesDeMontantEtMoovSim(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(100, 'MIN-100'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(1_000_000, 'MAX-1000000', provider: 'MOOV_SIM'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('MOOV_SIM', $this->decode($client)['provider']);
    }

    public function testUnMontantInvalideNeCreeAucuneLigne(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $before = $this->transactionTotal($merchant['email']);

        foreach ([99, 1_000_001] as $amount) {
            $this->postTransaction($client, $merchant['api_key'], $this->paymentBody($amount), $this->idempotencyKey());
            $this->assertProblem($client, 400, '/errors/validation');
        }

        $this->request($client, 'POST', '/api/v1/transactions', [
            'phone' => '+22890123452',
            'provider' => 'MTN_SIM',
            'merchant_reference' => 'SANS-MONTANT',
        ], [
            'X-API-Key' => $merchant['api_key'],
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);
        $this->assertProblem($client, 400, '/errors/validation');

        $client->request(
            'POST',
            '/api/v1/transactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_API_KEY' => $merchant['api_key'],
                'HTTP_IDEMPOTENCY_KEY' => $this->idempotencyKey(),
            ],
            content: '{"amount":5000.5,"phone":"+22890123452","provider":"MTN_SIM","merchant_reference":"FLOAT"}',
        );
        $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame($before, $this->transactionTotal($merchant['email']));
    }

    public function testLeTelephoneLOperateurEtLaReferenceSontValides(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(provider: 'ORANGE'), $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');

        $this->request($client, 'POST', '/api/v1/transactions', [
            'amount' => 5000,
            'provider' => 'MTN_SIM',
            'merchant_reference' => 'SANS-TEL',
        ], [
            'X-API-Key' => $merchant['api_key'],
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);
        $this->assertProblem($client, 400, '/errors/validation');

        $this->request($client, 'POST', '/api/v1/transactions', [
            'amount' => 5000,
            'phone' => '+22890123452',
            'provider' => 'MTN_SIM',
        ], [
            'X-API-Key' => $merchant['api_key'],
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(phone: '22890123452'), $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: str_repeat('R', 65)), $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(reference: str_repeat('R', 64)), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testLaDescriptionEstFacultative(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);

        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertNull($this->decode($client)['description']);

        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'AVEC-DESC', description: 'Recharge', withDescription: true),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('Recharge', $this->decode($client)['description']);

        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'DESC-VIDE', description: '', withDescription: true),
            $this->idempotencyKey(),
        );
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'DESC-LONGUE', description: str_repeat('d', 256), withDescription: true),
            $this->idempotencyKey(),
        );
        $this->assertProblem($client, 400, '/errors/validation');
    }

    public function testUnChampInconnuEtUneDeviseEtrangereSontRefuses(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $before = $this->transactionTotal($merchant['email']);

        $body = $this->paymentBody();
        $body['extra'] = 'non';
        $this->postTransaction($client, $merchant['api_key'], $body, $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');

        $body = $this->paymentBody(reference: 'EUR');
        $body['currency'] = 'EUR';
        $this->postTransaction($client, $merchant['api_key'], $body, $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame($before, $this->transactionTotal($merchant['email']));
    }

    public function testLeJwtSeulNeCreePasDePaiement(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $before = $this->transactionTotal($merchant['email']);

        $this->request($client, 'POST', '/api/v1/transactions', $this->paymentBody(), [
            'Authorization' => 'Bearer '.$merchant['token'],
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);

        $this->assertProblem($client, 401, '/errors/authentication');
        self::assertSame($before, $this->transactionTotal($merchant['email']));
    }

    public function testUnMarchandSuspenduNeCreePasDePaiementMaisResteConnecte(): void
    {
        $client = static::createClient();
        $account = $this->openMerchant($client);
        $merchant = $this->merchant($account['email']);
        $merchant->suspend();
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => $account['password'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $token = $this->stringValue($this->decode($client), 'token');

        $this->request($client, 'GET', '/api/v1/api-keys', null, [
            'Authorization' => 'Bearer '.$token,
        ]);
        $this->assertProblem($client, 403, '/errors/merchant-suspended');

        $before = $this->transactionTotal($account['email']);
        $this->postTransaction($client, $account['api_key'], $this->paymentBody(), $this->idempotencyKey());
        $this->assertProblem($client, 403, '/errors/merchant-suspended');
        self::assertSame($before, $this->transactionTotal($account['email']));
    }
}
