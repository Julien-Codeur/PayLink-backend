<?php

declare(strict_types=1);

namespace App\Tests\Functional\Merchant;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Infrastructure\Repository\ApiKeyRepository;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ApiKeyTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    public function testLaCleNestAfficheeQuUneFoisEtSonHashEstStocke(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $session = $this->login($client, $account);
        $headers = ['Authorization' => 'Bearer '.$session['token']];

        $this->request($client, 'POST', '/api/v1/api-keys', null, $headers);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decode($client);
        $plain = $this->stringValue($created, 'api_key');
        $prefix = $this->stringValue($created, 'prefix');
        self::assertMatchesRegularExpression('/^pl_test_[A-Za-z0-9]{32}$/', $plain);
        self::assertSame(substr($plain, 8, 8), $prefix);
        self::assertSame(8, \strlen($prefix));

        $stored = $this->apiKeys()->findOneByHash(hash('sha256', $plain));
        self::assertInstanceOf(ApiKey::class, $stored);
        self::assertSame(hash('sha256', $plain), $stored->getKeyHash());
        self::assertNotSame($plain, $stored->getKeyHash());
        self::assertNotSame($plain, $stored->getPrefix());

        $this->request($client, 'GET', '/api/v1/api-keys', headers: $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($plain, (string) $client->getResponse()->getContent());
        $items = $this->decode($client)['items'] ?? null;
        self::assertIsArray($items);
        self::assertIsArray($items[0] ?? null);
        self::assertSame($prefix, $items[0]['prefix']);

        $this->request($client, 'GET', '/api/v1/api-keys/'.$this->stringValue($created, 'id'), headers: $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertArrayNotHasKey('api_key', $this->decode($client));
        self::assertStringNotContainsString($plain, (string) $client->getResponse()->getContent());
    }

    public function testCreerUneCleSansJwtEstRefuse(): void
    {
        $client = static::createClient();
        $this->request($client, 'POST', '/api/v1/api-keys');
        $this->assertProblem($client, 401, '/errors/authentication');
    }

    public function testUneCleRevoqueeEstRefuseeEtSonUsageMetAJourLastUsedAt(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $session = $this->login($client, $account);
        $headers = ['Authorization' => 'Bearer '.$session['token']];

        self::mockTime('2026-03-01T00:00:00+00:00');
        $this->request($client, 'POST', '/api/v1/api-keys', null, $headers);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $plain = $this->stringValue($this->decode($client), 'api_key');
        $id = $this->stringValue($this->decode($client), 'id');

        $this->request($client, 'GET', '/api/v1/me', headers: ['X-API-Key' => $plain]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $first = $this->reload($plain);
        self::assertNotNull($first->getLastUsedAt());

        self::mockTime('2026-03-01T00:00:05+00:00');
        $this->request($client, 'GET', '/api/v1/me', headers: ['X-API-Key' => $plain]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $second = $this->reload($plain);
        self::assertNotNull($first->getLastUsedAt());
        self::assertGreaterThan($first->getLastUsedAt()->getTimestamp(), $second->getLastUsedAt()?->getTimestamp());

        $this->request($client, 'DELETE', '/api/v1/api-keys/'.$id, headers: $headers);
        self::assertSame(204, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertFalse($this->reload($plain)->isActive());

        $this->request($client, 'GET', '/api/v1/me', headers: ['X-API-Key' => $plain]);
        $this->assertProblem($client, 401, '/errors/authentication');
    }

    public function testLaccesALaCleDunAutreMarchandEstRefuse(): void
    {
        $client = static::createClient();
        $owner = $this->registerMerchant($client, name: 'Marchand A');
        $ownerSession = $this->login($client, $owner);
        $this->request($client, 'POST', '/api/v1/api-keys', null, [
            'Authorization' => 'Bearer '.$ownerSession['token'],
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decode($client);
        $id = $this->stringValue($created, 'id');
        $prefix = $this->stringValue($created, 'prefix');

        $other = $this->registerMerchant($client, name: 'Marchand B');
        $otherSession = $this->login($client, $other);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherSession['token']];

        $this->request($client, 'GET', '/api/v1/api-keys/'.$id, headers: $otherHeaders);
        $denied = $this->assertProblem($client, 403, '/errors/access-denied');
        self::assertStringNotContainsString($owner['email'], (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($prefix, (string) $client->getResponse()->getContent());
        self::assertIsString($denied['detail']);

        $this->request($client, 'DELETE', '/api/v1/api-keys/'.$id, headers: $otherHeaders);
        $this->assertProblem($client, 403, '/errors/access-denied');

        $stored = $this->apiKeys()->findOneById($id);
        self::assertInstanceOf(ApiKey::class, $stored);
        self::assertTrue($stored->isActive());

        $this->request($client, 'GET', '/api/v1/api-keys', headers: $otherHeaders);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($id, (string) $client->getResponse()->getContent());
    }

    public function testLaSixiemeCleActiveEstRefuseeJusquaUneRevocation(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $headers = ['Authorization' => 'Bearer '.$this->login($client, $account)['token']];
        $firstId = null;
        for ($index = 0; $index < 5; ++$index) {
            $this->request($client, 'POST', '/api/v1/api-keys', null, $headers);
            self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $firstId ??= $this->stringValue($this->decode($client), 'id');
        }

        $this->request($client, 'POST', '/api/v1/api-keys', null, $headers);
        $this->assertProblem($client, 409, '/errors/api-key-limit');
        self::assertCount(5, $this->activeItems($client, $headers));

        $this->request($client, 'DELETE', '/api/v1/api-keys/'.$firstId, headers: $headers);
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->request($client, 'POST', '/api/v1/api-keys', null, $headers);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertCount(5, $this->activeItems($client, $headers));
    }

    public function testDeuxClesAvecLeMemeHashSontRefuseesParLaBase(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $merchants = static::getContainer()->get(MerchantRepository::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(MerchantRepository::class, $merchants);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $merchant = $merchants->findOneByEmail($account['email']);
        self::assertInstanceOf(Merchant::class, $merchant);

        $hash = hash('sha256', 'pl_test_'.bin2hex(random_bytes(16)));
        $now = new \DateTimeImmutable();
        $entityManager->persist(new ApiKey($merchant, 'abcdefgh', $hash, $now));
        $entityManager->flush();
        $entityManager->persist(new ApiKey($merchant, 'ijklmnop', $hash, $now));

        try {
            $entityManager->flush();
            self::fail('La contrainte unique aurait dû refuser le second hash.');
        } catch (UniqueConstraintViolationException) {
            self::ensureKernelShutdown();
        }
    }

    private function apiKeys(): ApiKeyRepository
    {
        $repository = static::getContainer()->get(ApiKeyRepository::class);
        self::assertInstanceOf(ApiKeyRepository::class, $repository);

        return $repository;
    }

    private function reload(string $plain): ApiKey
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $key = $this->apiKeys()->findOneByHash(hash('sha256', $plain));
        self::assertInstanceOf(ApiKey::class, $key);

        return $key;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<mixed>
     */
    private function activeItems(KernelBrowser $client, array $headers): array
    {
        $this->request($client, 'GET', '/api/v1/api-keys', headers: $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $items = $this->decode($client)['items'] ?? null;
        self::assertIsArray($items);
        $active = [];
        foreach ($items as $item) {
            if (\is_array($item) && true === ($item['active'] ?? null)) {
                $active[] = $item;
            }
        }

        return $active;
    }
}
