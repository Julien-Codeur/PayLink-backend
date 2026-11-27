<?php

declare(strict_types=1);

namespace App\Payment\Domain\Service;

final class RequestHasher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function hash(array $payload): string
    {
        $encoded = json_encode($this->canonicalize($payload), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = $this->canonicalize($item);
            }

            return $items;
        }

        ksort($value);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[(string) $key] = $this->canonicalize($item);
        }

        return $sorted;
    }
}
