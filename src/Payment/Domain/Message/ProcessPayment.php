<?php

declare(strict_types=1);

namespace App\Payment\Domain\Message;

final readonly class ProcessPayment
{
    public function __construct(public string $transactionId)
    {
    }
}
