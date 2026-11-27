<?php

declare(strict_types=1);

namespace App\Payment\UserInterface;

use App\Payment\Domain\Enum\ProviderCode;
use App\Payment\Domain\Model\CreateTransactionCommand;
use App\Payment\Domain\Service\RequestHasher;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Component\HttpFoundation\Request;

final class TransactionRequestParser
{
    public function __construct(private readonly RequestHasher $hasher)
    {
    }

    public function parse(Request $request): CreateTransactionCommand
    {
        $data = JsonBody::decode($request, [
            'amount',
            'phone',
            'provider',
            'merchant_reference',
            'description',
            'currency',
        ]);

        $amount = $this->amount($data);
        $phone = $this->phone($data);
        $provider = $this->provider($data);
        $reference = $this->reference($data);
        $description = $this->description($data);
        $this->currency($data);

        $payload = [
            'amount' => $amount,
            'phone' => $phone,
            'provider' => $provider->value,
            'merchant_reference' => $reference,
        ];
        if (\array_key_exists('description', $data)) {
            $payload['description'] = $description;
        }
        if (\array_key_exists('currency', $data)) {
            $payload['currency'] = 'XOF';
        }

        return new CreateTransactionCommand(
            $amount,
            $phone,
            $provider,
            $reference,
            $description,
            $this->idempotencyKey($request),
            $this->hasher->hash($payload),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function amount(array $data): int
    {
        if (!\array_key_exists('amount', $data) || !\is_int($data['amount'])) {
            throw ApiProblemException::validation('Le montant doit être un entier.', '/amount');
        }
        if ($data['amount'] < 100) {
            throw ApiProblemException::validation('Le montant minimum est 100 XOF.', '/amount');
        }
        if ($data['amount'] > 1_000_000) {
            throw ApiProblemException::validation('Le montant maximum est 1 000 000 XOF.', '/amount');
        }

        return $data['amount'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function phone(array $data): string
    {
        $phone = $data['phone'] ?? null;
        if (!\is_string($phone) || 1 !== preg_match('/^\+228[0-9]{8}$/', $phone)) {
            throw ApiProblemException::validation('Le numéro doit commencer par +228 et contenir 8 chiffres.', '/phone');
        }

        return $phone;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function provider(array $data): ProviderCode
    {
        $provider = $data['provider'] ?? null;
        if (!\is_string($provider)) {
            throw ApiProblemException::validation('Opérateur invalide.', '/provider');
        }
        $code = ProviderCode::tryFrom($provider);
        if (!$code instanceof ProviderCode) {
            throw ApiProblemException::validation('Opérateur invalide.', '/provider');
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function reference(array $data): string
    {
        $reference = $data['merchant_reference'] ?? null;
        if (!\is_string($reference) || '' === $reference || mb_strlen($reference) > 64) {
            throw ApiProblemException::validation('La référence marchand est obligatoire et contient au plus 64 caractères.', '/merchant_reference');
        }

        return $reference;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function description(array $data): ?string
    {
        if (!\array_key_exists('description', $data) || null === $data['description']) {
            return null;
        }
        if (!\is_string($data['description']) || '' === $data['description'] || mb_strlen($data['description']) > 255) {
            throw ApiProblemException::validation('La description contient au plus 255 caractères.', '/description');
        }

        return $data['description'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function currency(array $data): void
    {
        if (!\array_key_exists('currency', $data)) {
            return;
        }
        if ('XOF' !== $data['currency']) {
            throw ApiProblemException::validation('La devise doit être XOF.', '/currency');
        }
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
