<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Time;

final class PaymentTimeout
{
    public function seconds(): int
    {
        $raw = $_SERVER['PAYLINK_PAYMENT_TIMEOUT_SECONDS'] ?? $_ENV['PAYLINK_PAYMENT_TIMEOUT_SECONDS'] ?? '60';
        if (!\is_string($raw) && !\is_int($raw)) {
            return 60;
        }

        $seconds = (int) $raw;

        return $seconds >= 1 ? $seconds : 60;
    }
}
