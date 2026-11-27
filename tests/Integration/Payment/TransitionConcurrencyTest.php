<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Shared\Http\ApiProblemException;
use App\Tests\Functional\Payment\PaymentTestCase;

final class TransitionConcurrencyTest extends PaymentTestCase
{
    public function testDeuxTransitionsSimultaneesNEcriventQuUnEvenement(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $this->postTransaction(
            $client,
            $merchant['api_key'],
            $this->paymentBody(reference: 'CONC-STATUT'),
            $this->idempotencyKey(),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $id = $this->stringValue($this->decode($client), 'id');

        $projectDir = \dirname(__DIR__, 3);
        $script = tempnam(sys_get_temp_dir(), 'paylink-transition');
        self::assertIsString($script);
        file_put_contents($script, $this->runner($projectDir));

        $processes = [];
        foreach ([1, 2] as $index) {
            $result = tempnam(sys_get_temp_dir(), 'paylink-transition-result');
            $error = tempnam(sys_get_temp_dir(), 'paylink-transition-error');
            self::assertIsString($result);
            self::assertIsString($error);
            $command = \sprintf(
                '%s %s %s %s',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($id),
                escapeshellarg($result),
            );
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['file', $error, 'a'],
                2 => ['file', $error, 'a'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'result' => $result, 'error' => $error, 'index' => $index];
        }

        $payloads = [];
        foreach ($processes as $item) {
            $exitCode = proc_close($item['process']);
            $errorText = (string) file_get_contents($item['error']);
            $raw = (string) file_get_contents($item['result']);
            self::assertSame(0, $exitCode, $errorText."\n".$raw);
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded);
            $payloads[] = $decoded;
        }

        $accepted = array_values(array_filter(
            $payloads,
            static fn (array $payload): bool => true === ($payload['ok'] ?? null),
        ));
        $rejected = array_values(array_filter(
            $payloads,
            static fn (array $payload): bool => false === ($payload['ok'] ?? null),
        ));
        self::assertCount(1, $accepted, (string) json_encode($payloads));
        self::assertCount(1, $rejected, (string) json_encode($payloads));
        self::assertSame(ApiProblemException::class, $rejected[0]['class'] ?? null);
        self::assertSame(422, $rejected[0]['status'] ?? null);
        self::assertSame('/errors/invalid-transition', $rejected[0]['type'] ?? null);

        static::ensureKernelShutdown();
        $transaction = static::getContainer()->get(TransactionRepository::class)->findOneForMerchant($this->merchant($merchant['email']), $id);
        self::assertInstanceOf(Transaction::class, $transaction);
        self::assertSame(TransactionStatus::Pending, $transaction->getStatus());
        self::assertCount(1, static::getContainer()->get(TransactionEventRepository::class)->findForTransaction($transaction));
    }

    private function runner(string $projectDir): string
    {
        $autoload = var_export($projectDir.'/vendor/autoload.php', true);
        $env = var_export($projectDir.'/.env', true);

        return <<<PHP
<?php
declare(strict_types=1);

use App\Kernel;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Shared\Http\ApiProblemException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

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
    \$kernel->boot();
    \$container = \$kernel->getContainer()->get('test.service_container');
    \$manager = \$container->get(EntityManagerInterface::class);
    \$lifecycle = \$container->get(TransactionLifecycle::class);
    \$transaction = \$manager->find(Transaction::class, Uuid::fromString(\$argv[1]));
    if (!\$transaction instanceof Transaction) {
        throw new RuntimeException('Transaction introuvable.');
    }
    \$lifecycle->apply(\$transaction, TransactionTransition::START, TransitionReason::START);
    file_put_contents(\$argv[2], json_encode(['ok' => true], JSON_THROW_ON_ERROR));
} catch (ApiProblemException \$exception) {
    file_put_contents(\$argv[2], json_encode([
        'ok' => false,
        'class' => \$exception::class,
        'status' => \$exception->statusCode,
        'type' => \$exception->type,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable \$throwable) {
    fwrite(STDERR, \$throwable::class.': '.\$throwable->getMessage().PHP_EOL.\$throwable->getTraceAsString());
    exit(1);
}
PHP;
    }
}
