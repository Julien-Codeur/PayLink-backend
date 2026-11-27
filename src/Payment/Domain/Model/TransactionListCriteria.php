<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

use App\Payment\Domain\Enum\TransactionStatus;

final readonly class TransactionListCriteria
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?TransactionStatus $status,
        public ?\DateTimeImmutable $createdFrom,
        public ?\DateTimeImmutable $createdTo,
        public ?int $amountMin,
        public ?int $amountMax,
        public ?string $query,
    ) {
    }
}
