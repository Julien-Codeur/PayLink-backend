<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Http\ApiProblemException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RequestValidator
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function validate(object $data): void
    {
        $violations = $this->validator->validate($data);
        if (0 === $violations->count()) {
            return;
        }

        /** @var list<array{pointer: string, detail: string}> $errors */
        $errors = [];
        foreach ($violations as $violation) {
            if (!$violation instanceof ConstraintViolation) {
                continue;
            }
            $errors[] = [
                'pointer' => $this->pointer($violation->getPropertyPath()),
                'detail' => (string) $violation->getMessage(),
            ];
        }

        throw new ApiProblemException('/errors/validation', 'Requête invalide', 400, $errors[0]['detail'] ?? 'Requête invalide.', $errors);
    }

    private function pointer(string $path): string
    {
        if ('' === $path) {
            return '/';
        }

        $normalized = str_replace(['][', '[', ']'], ['/', '', ''], $path);
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $normalized));

        return '/'.ltrim($snake, '/');
    }
}
