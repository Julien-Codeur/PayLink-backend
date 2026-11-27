<?php

declare(strict_types=1);

namespace App\Tests\Functional\Merchant;

use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Tests\Functional\ApiTestCase;

final class ProfileTest extends ApiTestCase
{
    public function testLeMarchandModifieSonNomEtSonMotDePasse(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $session = $this->login($client, $account);
        $headers = ['Authorization' => 'Bearer '.$session['token']];

        $this->request($client, 'PATCH', '/api/v1/me', [
            'name' => 'Boutique renommée',
        ], $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('Boutique renommée', $this->decode($client)['name']);

        $this->request($client, 'PATCH', '/api/v1/me', [
            'password' => 'nouveau-mot-10',
            'current_password' => $account['password'],
        ], $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => $account['password'],
        ]);
        $this->assertProblem($client, 401, '/errors/authentication');

        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => 'nouveau-mot-10',
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testUnNouveauMotDePasseTropCourtLaisseLancienValide(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $session = $this->login($client, $account);

        $this->request($client, 'PATCH', '/api/v1/me', [
            'password' => 'court',
            'current_password' => $account['password'],
        ], ['Authorization' => 'Bearer '.$session['token']]);
        $body = $this->assertProblem($client, 400, '/errors/validation');
        self::assertIsArray($body['errors']);
        self::assertIsArray($body['errors'][0]);
        self::assertSame('/password', $body['errors'][0]['pointer']);

        $this->login($client, $account);
    }

    public function testLaModificationDuProfilSansJwtEstRefusee(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);

        $this->request($client, 'PATCH', '/api/v1/me', ['name' => 'Piraté']);
        $this->assertProblem($client, 401, '/errors/authentication');

        $repository = static::getContainer()->get(MerchantRepository::class);
        self::assertInstanceOf(MerchantRepository::class, $repository);
        $merchant = $repository->findOneByEmail($account['email']);
        self::assertNotNull($merchant);
        self::assertSame('Boutique Akou', $merchant->getName());
    }
}
