<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

final readonly class OperatorDecision
{
    public function __construct(
        public bool $notifies,
        public bool $success,
        public int $delaySeconds,
        public ?string $failureCode,
    ) {
    }
}
