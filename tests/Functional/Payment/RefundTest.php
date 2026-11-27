<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Repository\RefundRepository;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class RefundTest extends PaymentTestCase
{
    public function testUnRemboursementPartielPuisLeRestantPasseARembourse(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->succeeded($client, $merchant, 5000);

        $this->postRefund($client, $merchant['api_key'], $id, 2000, $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertNull($client->getResponse()->headers->get('Idempotent-Replayed'));
        $partial = $this->decode($client);
        self::assertSame(2000, $partial['amount']);
        self::assertSame('SUCCEEDED', $partial['status']);
        self::assertSame('PARTIALLY_REFUNDED', $partial['transaction_status']);
        self::assertSame('XOF', $partial['currency']);

        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::PartiallyRefunded, $transaction->getStatus());
        $events = $this->events($transaction);
        self::assertCount(3, $events);
        self::assertSame(TransactionStatus::Success, $events[2]->getFromStatus());
        self::assertSame(TransactionStatus::PartiallyRefunded, $events[2]->getToStatus());
        self::assertSame(TransitionReason::REFUND_PARTIAL, $events[2]->getReason());

        $this->postRefund($client, $merchant['api_key'], $id, 3000, $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('REFUNDED', $this->decode($client)['transaction_status']);
        $refunded = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::Refunded, $refunded->getStatus());
        self::assertSame(2, $this->refundCount($id));
        $finalEvents = $this->events($refunded);
        self::assertCount(4, $finalEvents);
        self::assertSame(TransitionReason::REFUND_FULL, $finalEvents[3]->getReason());
    }

    public function testLaMemeCleRejoueLeCorpsFige(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->succeeded($client, $merchant, 5000);
        $key = $this->idempotencyKey();

        $this->postRefund($client, $merchant['api_key'], $id, 2000, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $first = (string) $client->getResponse()->getContent();

        $this->postRefund($client, $merchant['api_key'], $id, 3000, $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->postRefund($client, $merchant['api_key'], $id, 2000, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('true', $client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame($first, (string) $client->getResponse()->getContent());
        self::assertSame(2, $this->refundCount($id));
    }

    public function testLaMemeCleAvecUnAutreMontantRepond409(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->succeeded($client, $merchant, 5000);
        $key = $this->idempotencyKey();

        $this->postRefund($client, $merchant['api_key'], $id, 2000, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->postRefund($client, $merchant['api_key'], $id, 3000, $key);
        $this->assertProblem($client, 409, '/errors/idempotency-conflict');
        self::assertSame(1, $this->refundCount($id));
        self::assertSame(TransactionStatus::PartiallyRefunded, $this->load($merchant['email'], $id)->getStatus());
    }

    public function testUneTransactionNonRemboursableEstRefusee(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);

        foreach ([TransactionStatus::Initiated, TransactionStatus::Pending, TransactionStatus::Failed] as $status) {
            $id = $this->reach($client, $merchant, $status);
            $this->postRefund($client, $merchant['api_key'], $id, 1000, $this->idempotencyKey());
            $this->assertProblem($client, 422, '/errors/refund-not-allowed');
            self::assertSame(0, $this->refundCount($id));
            self::assertSame($status, $this->load($merchant['email'], $id)->getStatus());
        }

        $refunded = $this->succeeded($client, $merchant, 5000);
        $this->postRefund($client, $merchant['api_key'], $refunded, 5000, $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->postRefund($client, $merchant['api_key'], $refunded, 1, $this->idempotencyKey());
        $this->assertProblem($client, 422, '/errors/refund-not-allowed');
        self::assertSame(1, $this->refundCount($refunded));
    }

    public function testUnMontantAuDessusDuRestantEstRefuse(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->succeeded($client, $merchant, 5000);

        $this->postRefund($client, $merchant['api_key'], $id, 5001, $this->idempotencyKey());
        $this->assertProblem($client, 422, '/errors/refund-exceeds-balance');
        self::assertSame(TransactionStatus::Success, $this->load($merchant['email'], $id)->getStatus());
        self::assertSame(0, $this->refundCount($id));

        $this->postRefund($client, $merchant['api_key'], $id, 2000, $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->postRefund($client, $merchant['api_key'], $id, 3001, $this->idempotencyKey());
        $this->assertProblem($client, 422, '/errors/refund-exceeds-balance');
        self::assertSame(TransactionStatus::PartiallyRefunded, $this->load($merchant['email'], $id)->getStatus());
        self::assertSame(1, $this->refundCount($id));
    }

    public function testUneRequeteIncompleteEstRefusee(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->succeeded($client, $merchant, 5000);

        $this->request($client, 'POST', '/api/v1/transactions/'.$id.'/refunds', ['amount' => 1000], [
            'X-API-Key' => $merchant['api_key'],
        ]);
        $this->assertProblem($client, 400, '/errors/validation');

        $this->postRefund($client, $merchant['api_key'], $id, 0, $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');
        $this->postRefund($client, $merchant['api_key'], $id, -1, $this->idempotencyKey());
        $this->assertProblem($client, 400, '/errors/validation');
        $this->request($client, 'POST', '/api/v1/transactions/'.$id.'/refunds', ['amount' => 1000, 'note' => 'x'], [
            'X-API-Key' => $merchant['api_key'],
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);
        $this->assertProblem($client, 400, '/errors/validation');
        self::assertSame(0, $this->refundCount($id));
    }

    public function testUnMarchandSuspenduNeRemboursePas(): void
    {
        $client = static::createClient();
        $account = $this->openMerchant($client);
        $id = $this->succeeded($client, $account, 5000);
        $merchant = $this->merchant($account['email']);
        $merchant->suspend();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->flush();

        $this->postRefund($client, $account['api_key'], $id, 1000, $this->idempotencyKey());
        $this->assertProblem($client, 403, '/errors/merchant-suspended');
        self::assertSame(0, $this->refundCount($id));
        self::assertSame(TransactionStatus::Success, $this->load($account['email'], $id)->getStatus());
    }

    public function testLaCleApiEtLeJwtRemboursent(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $byKey = $this->succeeded($client, $merchant, 1500);
        $byToken = $this->succeeded($client, $merchant, 1500);

        $this->postRefund($client, $merchant['api_key'], $byKey, 1500, $this->idempotencyKey());
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame(1500, $this->decode($client)['amount']);

        $this->request($client, 'POST', '/api/v1/transactions/'.$byToken.'/refunds', ['amount' => 1500], [
            'Authorization' => 'Bearer '.$merchant['token'],
            'Idempotency-Key' => $this->idempotencyKey(),
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame(TransactionStatus::Refunded, $this->load($merchant['email'], $byKey)->getStatus());
        self::assertSame(TransactionStatus::Refunded, $this->load($merchant['email'], $byToken)->getStatus());
    }

    public function testLaMemeCleSurDeuxTransactionsCreeDeuxRemboursements(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $first = $this->succeeded($client, $merchant, 1000);
        $second = $this->succeeded($client, $merchant, 1000);
        $key = $this->idempotencyKey();

        $this->postRefund($client, $merchant['api_key'], $first, 1000, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->postRefund($client, $merchant['api_key'], $second, 1000, $key);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertNull($client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertSame(1, $this->refundCount($first));
        self::assertSame(1, $this->refundCount($second));
    }

    public function testLaTransactionDUnAutreMarchandRepond404(): void
    {
        $client = static::createClient();
        $owner = $this->openMerchant($client, 'Boutique A');
        $other = $this->openMerchant($client, 'Boutique B');
        $id = $this->succeeded($client, $owner, 5000);

        $this->postRefund($client, $other['api_key'], $id, 1000, $this->idempotencyKey());
        $this->assertProblem($client, 404, '/errors/not-found');
        self::assertSame(0, $this->refundCount($id));
        self::assertSame(TransactionStatus::Success, $this->load($owner['email'], $id)->getStatus());
    }

    public function testDeuxRemboursementsSimultanesDontLaSommeDepasse(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $id = $this->succeeded($client, $merchant, 5000);
        $body = json_encode(['amount' => 3000], \JSON_THROW_ON_ERROR);
        $projectDir = dirname(__DIR__, 3);
        $script = tempnam(sys_get_temp_dir(), 'paylink-refund');
        self::assertIsString($script);
        file_put_contents($script, $this->runner($projectDir));

        $processes = [];
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $result = tempnam(sys_get_temp_dir(), 'paylink-refund-result');
            $output = tempnam(sys_get_temp_dir(), 'paylink-refund-output');
            $error = tempnam(sys_get_temp_dir(), 'paylink-refund-error');
            self::assertIsString($result);
            self::assertIsString($output);
            self::assertIsString($error);
            $command = sprintf(
                '%s %s %s %s %s %s %s',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($id),
                escapeshellarg($merchant['api_key']),
                escapeshellarg($this->idempotencyKey()),
                escapeshellarg($body),
                escapeshellarg($result),
            );
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['file', $output, 'w'],
                2 => ['file', $error, 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'result' => $result, 'error' => $error];
        }

        $codes = [];
        foreach ($processes as $item) {
            $exitCode = proc_close($item['process']);
            $errorText = (string) file_get_contents($item['error']);
            $raw = (string) file_get_contents($item['result']);
            self::assertSame(0, $exitCode, $errorText."\n".$raw);
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded);
            self::assertIsInt($decoded['status']);
            $codes[] = $decoded['status'];
            if (422 === $decoded['status']) {
                $problem = json_decode((string) $decoded['body'], true);
                self::assertIsArray($problem);
                self::assertSame('/errors/refund-exceeds-balance', $problem['type']);
            }
        }

        sort($codes);
        self::assertSame([201, 422], $codes);
        static::ensureKernelShutdown();
        self::assertSame(1, $this->refundCount($id));
        $transaction = $this->load($merchant['email'], $id);
        self::assertSame(TransactionStatus::PartiallyRefunded, $transaction->getStatus());
        self::assertSame(3000, static::getContainer()->get(RefundRepository::class)->sumSucceeded($transaction));
    }

    /**
     * @param array{api_key: string, token: string, email: string, password: string, id: string} $merchant
     */
    private function succeeded(KernelBrowser $client, array $merchant, int $amount): string
    {
        return $this->reach($client, $merchant, TransactionStatus::Success, $amount);
    }

    /**
     * @param array{api_key: string, token: string, email: string, password: string, id: string} $merchant
     */
    private function reach(KernelBrowser $client, array $merchant, TransactionStatus $status, int $amount = 5000): string
    {
        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(amount: $amount, reference: 'RF-'.substr($this->idempotencyKey(), 0, 8)),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');
        if (TransactionStatus::Initiated === $status) {
            return $id;
        }

        $lifecycle = static::getContainer()->get(TransactionLifecycle::class);
        self::assertInstanceOf(TransactionLifecycle::class, $lifecycle);
        $transaction = $this->managed($merchant['email'], $id);
        $lifecycle->apply($transaction, TransactionTransition::START, TransitionReason::START);
        if (TransactionStatus::Pending === $status) {
            return $id;
        }
        if (TransactionStatus::Failed === $status) {
            $lifecycle->apply($transaction, TransactionTransition::FAIL, TransitionReason::FAIL, 'insufficient_funds');

            return $id;
        }

        $lifecycle->apply($transaction, TransactionTransition::SUCCEED, TransitionReason::SUCCEED);

        return $id;
    }

    private function postRefund(KernelBrowser $client, string $apiKey, string $id, int $amount, string $idempotencyKey): void
    {
        $this->request($client, 'POST', '/api/v1/transactions/'.$id.'/refunds', ['amount' => $amount], [
            'X-API-Key' => $apiKey,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    private function managed(string $email, string $id): Transaction
    {
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($email), $id);
        self::assertInstanceOf(Transaction::class, $transaction);

        return $transaction;
    }

    private function load(string $email, string $id): Transaction
    {
        static::ensureKernelShutdown();

        return $this->managed($email, $id);
    }

    private function refundCount(string $id): int
    {
        static::ensureKernelShutdown();

        return static::getContainer()->get(RefundRepository::class)->countForTransaction(Uuid::fromString($id));
    }

    /**
     * @return list<\App\Payment\Domain\Entity\TransactionEvent>
     */
    private function events(Transaction $transaction): array
    {
        return static::getContainer()->get(TransactionEventRepository::class)->findForTransaction($transaction);
    }

    private function runner(string $projectDir): string
    {
        $autoload = var_export($projectDir.'/vendor/autoload.php', true);
        $env = var_export($projectDir.'/.env', true);

        return <<<PHP
<?php
declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require {$autoload};

\$_SERVER['APP_ENV'] = 'test';
\$_SERVER['APP_DEBUG'] = '1';
\$_ENV['APP_ENV'] = 'test';
\$_ENV['APP_DEBUG'] = '1';

(new Dotenv())->bootEnv({$env}, 'test');

\$_SERVER['APP_ENV'] = 'test';
\$_ENV['APP_ENV'] = 'test';

try {
    \$kernel = new Kernel('test', true);
    \$request = Request::create(
        '/api/v1/transactions/'.\$argv[1].'/refunds',
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => \$argv[2],
            'HTTP_IDEMPOTENCY_KEY' => \$argv[3],
        ],
        \$argv[4],
    );
    \$response = \$kernel->handle(\$request);
    file_put_contents(\$argv[5], json_encode([
        'status' => \$response->getStatusCode(),
        'body' => \$response->getContent(),
    ], JSON_THROW_ON_ERROR));
    \$kernel->terminate(\$request, \$response);
} catch (Throwable \$throwable) {
    fwrite(STDERR, \$throwable::class.': '.\$throwable->getMessage().PHP_EOL.\$throwable->getTraceAsString());
    exit(1);
}
PHP;
    }
}
