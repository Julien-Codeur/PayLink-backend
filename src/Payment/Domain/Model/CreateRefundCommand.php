<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

final readonly class CreateRefundCommand
{
    public function __construct(
        public int $amount,
        public string $idempotencyKey,
        public string $requestHash,
    ) {
    }
}
