<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

use App\Payment\Domain\Enum\ProviderCode;

final readonly class CreateTransactionCommand
{
    public function __construct(
        public int $amount,
        public string $phone,
        public ProviderCode $provider,
        public string $merchantReference,
        public ?string $description,
        public string $idempotencyKey,
        public string $requestHash,
    ) {
    }
}
