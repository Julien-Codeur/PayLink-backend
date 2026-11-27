<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use Doctrine\ORM\EntityManagerInterface;

final class SuspendedMerchantAccessTest extends PaymentTestCase
{
    public function testUnMarchandSuspenduEstRefuseEnLecture(): void
    {
        $client = static::createClient();
        $account = $this->openMerchant($client);
        $phone = '+22890123452';
        $this->postTransaction(
            $client,
            $account['api_key'],
            $this->paymentBody(phone: $phone, reference: 'SUSPENDU-1'),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $merchant = $this->merchant($account['email']);
        $merchant->suspend();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->flush();

        $this->request($client, 'GET', '/api/v1/transactions', null, ['X-API-Key' => $account['api_key']]);
        $this->assertProblem($client, 403, '/errors/merchant-suspended');
        self::assertStringNotContainsString($phone, (string) $client->getResponse()->getContent());

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, ['X-API-Key' => $account['api_key']]);
        $this->assertProblem($client, 403, '/errors/merchant-suspended');
        self::assertStringNotContainsString($phone, (string) $client->getResponse()->getContent());

        $this->request($client, 'GET', '/api/v1/transactions', null, [
            'Authorization' => 'Bearer '.$account['token'],
        ]);
        $this->assertProblem($client, 403, '/errors/merchant-suspended');

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, [
            'Authorization' => 'Bearer '.$account['token'],
        ]);
        $this->assertProblem($client, 403, '/errors/merchant-suspended');

        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => $account['password'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $token = $this->stringValue($this->decode($client), 'token');

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, [
            'Authorization' => 'Bearer '.$token,
        ]);
        $this->assertProblem($client, 403, '/errors/merchant-suspended');
        self::assertStringNotContainsString($phone, (string) $client->getResponse()->getContent());
    }
}
