<?php

declare(strict_types=1);

namespace App\Payment\UserInterface;

use App\Payment\Domain\Model\CreateRefundCommand;
use App\Payment\Domain\Service\RequestHasher;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Component\HttpFoundation\Request;

final class RefundRequestParser
{
    public function __construct(private readonly RequestHasher $hasher)
    {
    }

    public function parse(Request $request): CreateRefundCommand
    {
        $data = JsonBody::decode($request, ['amount']);
        $amount = $this->amount($data);

        return new CreateRefundCommand($amount, $this->idempotencyKey($request), $this->hasher->hash(['amount' => $amount]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function amount(array $data): int
    {
        if (!\array_key_exists('amount', $data) || !\is_int($data['amount'])) {
            throw ApiProblemException::validation('Le montant doit être un entier.', '/amount');
        }
        if ($data['amount'] < 1) {
            throw ApiProblemException::validation('Le montant minimum est 1 XOF.', '/amount');
        }

        return $data['amount'];
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->headers->get('Idempotency-Key');
        if (!\is_string($key) || '' === $key) {
            throw ApiProblemException::validation('L\'en-tête Idempotency-Key est obligatoire.');
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw ApiProblemException::validation('La clé d\'idempotence contient un caractère interdit.');
        }
        $length = mb_strlen($key);
        if ($length < 16 || $length > 64) {
            throw ApiProblemException::validation('La clé d\'idempotence doit contenir entre 16 et 64 caractères.');
        }

        return $key;
    }
}
