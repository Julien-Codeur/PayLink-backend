<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class ApiTestCase extends WebTestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$migrated) {
            return;
        }

        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $input = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $code = $application->run($input, $output);
        self::ensureKernelShutdown();
        if (0 !== $code) {
            throw new \RuntimeException($output->fetch());
        }

        self::$migrated = true;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function request(KernelBrowser $client, string $method, string $uri, ?array $body = null, array $headers = []): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $encoded = null;
        if (null !== $body) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $client->request($method, $uri, server: $server, content: $encoded);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertProblem(KernelBrowser $client, int $status, string $type): array
    {
        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('content-type'),
        );
        $body = $this->decode($client);
        self::assertSame($type, $body['type']);
        self::assertSame($status, $body['status']);
        self::assertIsString($body['title']);
        self::assertIsString($body['detail']);
        self::assertArrayHasKey('correlation_id', $body);

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function stringValue(array $payload, string $field): string
    {
        self::assertArrayHasKey($field, $payload);
        self::assertIsString($payload[$field]);

        return $payload[$field];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array{token: string, refresh_token: string}
     */
    protected function login(KernelBrowser $client, array $account): array
    {
        $this->request($client, 'POST', '/api/v1/auth/login', [
            'email' => $account['email'],
            'password' => $account['password'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = $this->decode($client);

        return [
            'token' => $this->stringValue($body, 'token'),
            'refresh_token' => $this->stringValue($body, 'refresh_token'),
        ];
    }

    /**
     * @return array{email: string, password: string, id: string}
     */
    protected function registerMerchant(KernelBrowser $client, string $password = 'password-ok-1', string $name = 'Boutique Akou'): array
    {
        $email = bin2hex(random_bytes(6)).'@example.test';
        $this->request($client, 'POST', '/api/v1/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = $this->decode($client);

        return [
            'email' => $email,
            'password' => $password,
            'id' => $this->stringValue($body, 'id'),
        ];
    }
}
