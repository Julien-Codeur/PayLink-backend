<?php

declare(strict_types=1);

namespace App\Tests\Functional\Merchant;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Domain\Enum\MerchantStatus;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterTest extends ApiTestCase
{
    public function testLinscriptionCreeUnMarchandActifSansRenvoyerLeMotDePasse(): void
    {
        $client = static::createClient();
        $email = 'Shop.'.bin2hex(random_bytes(4)).'@Example.test';
        $password = 'password-ok-1';

        $this->request($client, 'POST', '/api/v1/auth/register', [
            'name' => '  Boutique Akou  ',
            'email' => $email,
            'password' => $password,
        ]);

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('content-type'));
        $body = $this->decode($client);
        self::assertSame('Boutique Akou', $body['name']);
        self::assertSame(strtolower($email), $body['email']);
        self::assertSame('ACTIVE', $body['status']);
        self::assertArrayNotHasKey('password', $body);
        self::assertStringNotContainsString($password, (string) $client->getResponse()->getContent());

        $merchant = $this->merchant($email);
        self::assertInstanceOf(Merchant::class, $merchant);
        self::assertSame(MerchantStatus::Active, $merchant->getStatus());
        self::assertNotSame($password, $merchant->getPassword());
        self::assertStringStartsWith('$', $merchant->getPassword());
        self::assertNotContains('ROLE_ADMIN', $merchant->getRoles());
        self::assertContains('ROLE_MERCHANT', $merchant->getRoles());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($merchant, $password));
    }

    public function testUnEmailDejaUtiliseEstRefuseQuelleQueSoitLaCasse(): void
    {
        $client = static::createClient();
        $email = 'shop.'.bin2hex(random_bytes(4)).'@example.test';
        $this->request($client, 'POST', '/api/v1/auth/register', [
            'name' => 'Boutique',
            'email' => $email,
            'password' => 'password-ok-1',
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $this->request($client, 'POST', '/api/v1/auth/register', [
            'name' => 'Autre',
            'email' => strtoupper($email),
            'password' => 'password-ok-2',
        ]);

        $body = $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame('/email', $this->pointer($body));
        self::assertSame(1, $this->merchants()->count(['email' => strtolower($email)]));
    }

    public function testUnMotDePasseDeNeufCaracteresEstRefuse(): void
    {
        $client = static::createClient();
        $email = bin2hex(random_bytes(6)).'@example.test';
        $this->request($client, 'POST', '/api/v1/auth/register', [
            'name' => 'Boutique',
            'email' => $email,
            'password' => '123456789',
        ]);

        $body = $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame('/password', $this->pointer($body));
        self::assertSame(0, $this->merchants()->count(['email' => $email]));
    }

    public function testUnChampObligatoireManquantEstRefuse(): void
    {
        $client = static::createClient();
        $email = bin2hex(random_bytes(6)).'@example.test';
        $this->request($client, 'POST', '/api/v1/auth/register', [
            'email' => $email,
            'password' => 'password-ok-1',
        ]);

        $body = $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame('/name', $this->pointer($body));
        self::assertSame(0, $this->merchants()->count(['email' => $email]));
    }

    public function testUnChampInconnuEstRefuse(): void
    {
        $client = static::createClient();
        $email = bin2hex(random_bytes(6)).'@example.test';
        $this->request($client, 'POST', '/api/v1/auth/register', [
            'name' => 'Boutique',
            'email' => $email,
            'password' => 'password-ok-1',
            'role' => 'ROLE_ADMIN',
        ]);

        $body = $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame('/role', $this->pointer($body));
        self::assertNull($this->merchant($email));
    }

    private function merchants(): MerchantRepository
    {
        $repository = static::getContainer()->get(MerchantRepository::class);
        self::assertInstanceOf(MerchantRepository::class, $repository);

        return $repository;
    }

    private function merchant(string $email): ?Merchant
    {
        return $this->merchants()->findOneByEmail(strtolower($email));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function pointer(array $body): string
    {
        self::assertIsArray($body['errors']);
        $error = $body['errors'][0] ?? null;
        self::assertIsArray($error);
        self::assertIsString($error['pointer']);

        return $error['pointer'];
    }
}
