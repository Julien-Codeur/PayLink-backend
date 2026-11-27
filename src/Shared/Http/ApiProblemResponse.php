<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiProblemResponse extends JsonResponse
{
    public function __construct(ApiProblemException $problem, ?string $correlationId = null)
    {
        $payload = [
            'type' => $problem->type,
            'title' => $problem->title,
            'status' => $problem->statusCode,
            'detail' => $problem->getMessage(),
        ];
        if ([] !== $problem->errors) {
            $payload['errors'] = $problem->errors;
        }
        if (null !== $correlationId && '' !== $correlationId) {
            $payload['correlation_id'] = $correlationId;
        }

        parent::__construct($payload, $problem->statusCode, ['Content-Type' => 'application/problem+json']);
        if (null !== $problem->retryAfter) {
            $this->headers->set('Retry-After', (string) $problem->retryAfter);
        }
    }
}
