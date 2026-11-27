<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Psr\Log\AbstractLogger;

final class CollectingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : 'error',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
