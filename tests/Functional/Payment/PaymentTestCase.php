<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use App\Payment\Domain\Model\TransactionListCriteria;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class PaymentTestCase extends ApiTestCase
{
    /**
     * @return array{api_key: string, token: string, email: string, password: string, id: string}
     */
    protected function openMerchant(KernelBrowser $client, string $name = 'Boutique Akou'): array
    {
        $account = $this->registerMerchant($client, name: $name);
        $session = $this->login($client, $account);
        $this->request($client, 'POST', '/api/v1/api-keys', null, [
            'Authorization' => 'Bearer '.$session['token'],
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decode($client);

        return [
            'api_key' => $this->stringValue($created, 'api_key'),
            'token' => $session['token'],
            'email' => $account['email'],
            'password' => $account['password'],
            'id' => $account['id'],
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $extraHeaders
     */
    protected function postTransaction(KernelBrowser $client, string $apiKey, array $body, string $idempotencyKey, array $extraHeaders = []): void
    {
        $extraHeaders['X-API-Key'] = $apiKey;
        $extraHeaders['Idempotency-Key'] = $idempotencyKey;
        $this->request($client, 'POST', '/api/v1/transactions', $body, $extraHeaders);
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentBody(
        int $amount = 5000,
        string $reference = 'CMD-2026-0042',
        string $phone = '+22890123452',
        string $provider = 'MTN_SIM',
        ?string $description = null,
        bool $withDescription = false,
    ): array {
        $body = [
            'amount' => $amount,
            'phone' => $phone,
            'provider' => $provider,
            'merchant_reference' => $reference,
        ];
        if ($withDescription) {
            $body['description'] = $description;
        }

        return $body;
    }

    protected function idempotencyKey(int $length = 32): string
    {
        return substr(bin2hex(random_bytes(40)), 0, $length);
    }

    protected function transactionTotal(string $email): int
    {
        $merchant = $this->merchant($email);

        return static::getContainer()->get(TransactionRepository::class)->search(
            $merchant,
            new TransactionListCriteria(1, 1, null, null, null, null, null, null),
        )->total;
    }

    protected function merchant(string $email): Merchant
    {
        $merchant = static::getContainer()->get(MerchantRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(Merchant::class, $merchant);

        return $merchant;
    }
}
