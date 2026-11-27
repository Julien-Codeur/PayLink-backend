<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

final readonly class TransactionResult
{
    public function __construct(
        public string $body,
        public int $statusCode,
        public bool $replayed,
    ) {
    }
}
