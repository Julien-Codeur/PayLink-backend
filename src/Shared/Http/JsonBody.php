<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\Request;

final class JsonBody
{
    /**
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    public static function decode(Request $request, array $allowed): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            $data = [];
        } else {
            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ApiProblemException::validation('Le corps JSON est illisible.');
            }
            if (!\is_array($decoded) || array_is_list($decoded)) {
                throw ApiProblemException::validation('Le corps JSON doit être un objet.');
            }
            $data = $decoded;
        }

        $unknown = array_diff(array_keys($data), $allowed);
        if ([] !== $unknown) {
            $field = array_values($unknown)[0];
            throw ApiProblemException::validation('Champ inconnu.', '/'.$field);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function requiredString(array $data, string $field): string
    {
        if (!isset($data[$field]) || !\is_string($data[$field])) {
            throw ApiProblemException::validation('Valeur invalide.', '/'.$field);
        }

        return $data[$field];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optionalString(array $data, string $field): ?string
    {
        if (!\array_key_exists($field, $data) || null === $data[$field]) {
            return null;
        }
        if (!\is_string($data[$field])) {
            throw ApiProblemException::validation('Valeur invalide.', '/'.$field);
        }

        return $data[$field];
    }
}
