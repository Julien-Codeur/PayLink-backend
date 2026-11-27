<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Infrastructure\Repository\TransactionRepository;

final class IdempotencyConcurrencyTest extends PaymentTestCase
{
    public function testDeuxRequetesSimultaneesNeCreentQuUneLigne(): void
    {
        $client = static::createClient();
        $merchant = $this->openMerchant($client);
        $key = $this->idempotencyKey();
        $body = json_encode($this->paymentBody(reference: 'CONC-1'), \JSON_THROW_ON_ERROR);
        $projectDir = dirname(__DIR__, 3);
        $script = tempnam(sys_get_temp_dir(), 'paylink-post');
        self::assertIsString($script);
        file_put_contents($script, $this->runner($projectDir));

        $processes = [];
        foreach ([1, 2] as $index) {
            $result = tempnam(sys_get_temp_dir(), 'paylink-result');
            $output = tempnam(sys_get_temp_dir(), 'paylink-output');
            $error = tempnam(sys_get_temp_dir(), 'paylink-error');
            self::assertIsString($result);
            self::assertIsString($output);
            self::assertIsString($error);
            $command = sprintf(
                '%s %s %s %s %s %s',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($merchant['api_key']),
                escapeshellarg($key),
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

        $ids = [];
        foreach ($payloads as $payload) {
            self::assertSame(201, $payload['status'], (string) $payload['body']);
            $decodedBody = json_decode((string) $payload['body'], true);
            self::assertIsArray($decodedBody);
            self::assertIsString($decodedBody['id']);
            $ids[] = $decodedBody['id'];
        }

        self::assertSame($ids[0], $ids[1]);
        self::assertSame(1, static::getContainer()->get(TransactionRepository::class)->countByMerchantAndKey($this->merchant($merchant['email']), $key));
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
        '/api/v1/transactions',
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => \$argv[1],
            'HTTP_IDEMPOTENCY_KEY' => \$argv[2],
        ],
        \$argv[3],
    );
    \$response = \$kernel->handle(\$request);
    file_put_contents(\$argv[4], json_encode([
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
