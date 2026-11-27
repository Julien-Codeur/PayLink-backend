<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class CorrelationId
{
    public static function from(Request $request): string
    {
        $existing = $request->attributes->get('correlation_id');
        if (\is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $header = $request->headers->get('X-Request-Id');
        $id = \is_string($header) && 1 === preg_match('/^[A-Za-z0-9_-]{8,64}$/', $header)
            ? $header
            : (string) Uuid::v7();
        $request->attributes->set('correlation_id', $id);

        return $id;
    }
}
