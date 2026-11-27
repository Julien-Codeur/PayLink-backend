<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Psr\Log\AbstractLogger;

final class ErrorLogLogger extends AbstractLogger
{
    /**
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $line = json_encode([
            'level' => \is_string($level) ? $level : 'error',
            'message' => (string) $message,
            'context' => $this->scalars($context),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        error_log($line);
    }

    /**
     * @param mixed[] $context
     *
     * @return array<string, int|string>
     */
    private function scalars(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (\is_string($key) && (\is_string($value) || \is_int($value))) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
