<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

final class TransactionIsolationTest extends PaymentTestCase
{
    public function testUnMarchandNeVoitPasLaTransactionDunAutre(): void
    {
        $client = static::createClient();
        $owner = $this->openMerchant($client, 'Boutique B');
        $other = $this->openMerchant($client, 'Boutique A');

        $this->postTransaction(
            $client,
            $owner['api_key'],
            $this->paymentBody(5000, 'REF-B', '+22891111111'),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, ['X-API-Key' => $other['api_key']]);
        $this->assertHidden($client);

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, [
            'Authorization' => 'Bearer '.$other['token'],
        ]);
        $this->assertHidden($client);

        foreach ([
            '/api/v1/transactions',
            '/api/v1/transactions?q=REF-B',
            '/api/v1/transactions?q='.urlencode('+22891111111'),
        ] as $uri) {
            $this->request($client, 'GET', $uri, null, ['X-API-Key' => $other['api_key']]);
            self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($id, $content);
            self::assertStringNotContainsString('REF-B', $content);
            self::assertStringNotContainsString('5000', $content);
        }

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, ['X-API-Key' => $owner['api_key']]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('REF-B', $this->decode($client)['merchant_reference']);
    }

    private function assertHidden(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $this->assertProblem($client, 404, '/errors/not-found');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('5000', $content);
        self::assertStringNotContainsString('REF-B', $content);
    }
}
