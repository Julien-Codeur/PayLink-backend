<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ListTransactionsTest extends PaymentTestCase
{
    use ClockSensitiveTrait;

    public function testLeProprietaireConsulteLeDetailAvecLaCleOuLeJeton(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $detail = $this->decode($client);
        self::assertSame('INITIATED', $detail['status']);
        self::assertSame(5000, $detail['amount']);
        self::assertSame('XOF', $detail['currency']);
        self::assertSame([], $detail['events']);
        self::assertNull($detail['failure_reason']);

        $this->request($client, 'GET', '/api/v1/transactions/'.$id, null, [
            'Authorization' => 'Bearer '.$merchant['token'],
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($id, $this->decode($client)['id']);

        $this->request($client, 'GET', '/api/v1/transactions/'.str_repeat('a', 36), null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 404, '/errors/not-found');
        $this->request($client, 'GET', '/api/v1/transactions/pas-un-uuid', null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 404, '/errors/not-found');
    }

    public function testLesFiltresLaPaginationEtLeTri(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);

        self::mockTime('2026-01-01 10:00:00');
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(1500, 'AAA', '+22890000001'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $first = $this->stringValue($this->decode($client), 'id');

        self::mockTime('2026-01-05 10:00:00');
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(5000, 'BBB', '+22890000002'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $second = $this->stringValue($this->decode($client), 'id');

        self::mockTime('2026-01-10 10:00:00');
        $this->postTransaction($client, $merchant['api_key'], $this->paymentBody(8000, 'CCC', '+22890000003'), $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $third = $this->stringValue($this->decode($client), 'id');

        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($merchant['email']), $third);
        self::assertInstanceOf(Transaction::class, $transaction);
        $lifecycle = static::getContainer()->get(TransactionLifecycle::class);
        self::assertInstanceOf(TransactionLifecycle::class, $lifecycle);
        $lifecycle->apply($transaction, TransactionTransition::START, TransitionReason::START);
        $lifecycle->apply($transaction, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);

        $this->request($client, 'GET', '/api/v1/transactions?status=SUCCESS', null, ['X-API-Key' => $merchant['api_key']]);
        $success = $this->assertList($client, 1);
        self::assertSame($third, $success[0]['id']);
        self::assertArrayNotHasKey('events', $success[0]);

        $this->request($client, 'GET', '/api/v1/transactions?created_from=2026-01-10', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$third], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?created_to=2026-01-02', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$first], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?amount_min=6000', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$third], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?amount_max=2000', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$first], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?q=BBB', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$second], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?q=BB', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$second], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?q='.urlencode('+22890000001'), null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$first], $this->ids($this->assertList($client, 1)));

        $this->request($client, 'GET', '/api/v1/transactions?q=ZZZ', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([], $this->assertList($client, 0));

        $this->request($client, 'GET', '/api/v1/transactions?items_per_page=2&page=1', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $page = $this->decode($client);
        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['items_per_page']);
        self::assertSame([$third, $second], $this->ids($this->items($page)));

        $this->request($client, 'GET', '/api/v1/transactions?items_per_page=2&page=2', null, ['X-API-Key' => $merchant['api_key']]);
        self::assertSame([$first], $this->ids($this->items($this->decode($client))));

        $this->request($client, 'GET', '/api/v1/transactions/'.$third, null, ['X-API-Key' => $merchant['api_key']]);
        $detail = $this->decode($client);
        self::assertSame('SUCCESS', $detail['status']);
        self::assertIsArray($detail['events']);
        self::assertCount(2, $detail['events']);
    }

    public function testLaListeExigeUneAuthentificationEtDesFiltresValides(): void
    {
        $client = static::createClient();
        $this->request($client, 'GET', '/api/v1/transactions');
        $this->assertProblem($client, 401, '/errors/authentication');

        $merchant = $this->openMerchant($client);
        $this->request($client, 'GET', '/api/v1/transactions?page=0', null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 400, '/errors/validation');
        $this->request($client, 'GET', '/api/v1/transactions?status=NOPE', null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 400, '/errors/validation');
        $this->request($client, 'GET', '/api/v1/transactions?created_from=demain', null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 400, '/errors/validation');
        $this->request($client, 'GET', '/api/v1/transactions?items_per_page=101', null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 400, '/errors/validation');
        $this->request($client, 'GET', '/api/v1/transactions?page=1001', null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 400, '/errors/validation');
        $this->request($client, 'GET', '/api/v1/transactions?q='.str_repeat('a', 101), null, ['X-API-Key' => $merchant['api_key']]);
        $this->assertProblem($client, 400, '/errors/validation');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assertList(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $total): array
    {
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $page = $this->decode($client);
        self::assertSame($total, $page['total']);

        return $this->items($page);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $page): array
    {
        self::assertIsArray($page['items']);
        $items = [];
        foreach ($page['items'] as $item) {
            self::assertIsArray($item);
            $row = [];
            foreach ($item as $key => $value) {
                self::assertIsString($key);
                $row[$key] = $value;
            }
            $items[] = $row;
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private function ids(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            self::assertIsString($item['id']);
            $ids[] = $item['id'];
        }

        return $ids;
    }
}
