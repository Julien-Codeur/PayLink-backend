<?php

declare(strict_types=1);

namespace App\Tests\Functional\Merchant;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Domain\Entity\RefreshToken;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Merchant\Infrastructure\Repository\RefreshTokenRepository;
use App\Tests\Functional\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class LoginTest extends ApiTestCase
{
    public function testLaConnexionRenvoieUnJwtDUneHeureEtUnRefreshTokenDeSeptJours(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $session = $this->login($client, $account);

        $payload = $this->jwtPayload($session['token']);
        self::assertSame(3600, $payload['exp'] - $payload['iat']);
        self::assertSame($account['id'], $payload['merchant_id']);

        $stored = $this->refreshTokens()->findOneByHash(hash('sha256', $session['refresh_token']));
        self::assertInstanceOf(RefreshToken::class, $stored);
        $expected = $stored->getCreatedAt()->modify('+7 days');
        self::assertSame($expected->getTimestamp(), $stored->getExpiresAt()->getTimestamp());
    }

    public function testLeRefreshTokenTourneEtLeNouveauJwtOuvreLeProfil(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);
        $session = $this->login($client, $account);

        $this->request($client, 'POST', '/api/v1/auth/refresh', [
            'refresh_token' => $session['refresh_token'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $renewed = $this->decode($client);
        $token = $this->stringValue($renewed, 'token');
        $refresh = $this->stringValue($renewed, 'refresh_token');
        self::assertNotSame($session['refresh_token'], $refresh);

        $this->request($client, 'GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$token]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($account['email'], $this->decode($client)['email']);

        $this->request($client, 'POST', '/api/v1/auth/refresh', [
            'refresh_token' => $session['refresh_token'],
        ]);
        $this->assertProblem($client, 401, '/errors/authentication');
    }

    public function testUnMauvaisMotDePasseOuUnJetonInvalideEstRefuse(): void
    {
        $client = static::createClient();
        $account = $this->registerMerchant($client);

        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => 'pas-le-bon-mot',
        ]);
        $wrongPassword = $this->assertProblem($client, 401, '/errors/authentication');

        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => 'inconnu-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'pas-le-bon-mot',
        ]);
        $unknown = $this->assertProblem($client, 401, '/errors/authentication');
        self::assertSame($wrongPassword['detail'], $unknown['detail']);

        $this->request($client, 'POST', '/api/v1/auth/refresh', [
            'refresh_token' => bin2hex(random_bytes(32)),
        ]);
        $this->assertProblem($client, 401, '/errors/authentication');

        $merchant = $this->merchants()->findOneByEmail($account['email']);
        self::assertInstanceOf(Merchant::class, $merchant);
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $jwt);
        $expired = $jwt->createFromPayload($merchant, ['exp' => time() - 3600]);
        $this->request($client, 'GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$expired]);
        $this->assertProblem($client, 401, '/errors/authentication');

        $this->request($client, 'GET', '/api/v1/me');
        $this->assertProblem($client, 401, '/errors/authentication');
    }

    private function merchants(): MerchantRepository
    {
        $repository = static::getContainer()->get(MerchantRepository::class);
        self::assertInstanceOf(MerchantRepository::class, $repository);

        return $repository;
    }

    private function refreshTokens(): RefreshTokenRepository
    {
        $repository = static::getContainer()->get(RefreshTokenRepository::class);
        self::assertInstanceOf(RefreshTokenRepository::class, $repository);

        return $repository;
    }

    /**
     * @return array{exp: int, iat: int, merchant_id: string}
     */
    private function jwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $segment = $parts[1].str_repeat('=', (4 - \strlen($parts[1]) % 4) % 4);
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        self::assertIsString($json);
        $payload = json_decode($json, true);
        self::assertIsArray($payload);
        self::assertIsInt($payload['exp']);
        self::assertIsInt($payload['iat']);
        self::assertIsString($payload['merchant_id']);

        return [
            'exp' => $payload['exp'],
            'iat' => $payload['iat'],
            'merchant_id' => $payload['merchant_id'],
        ];
    }
}
