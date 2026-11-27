<?php

declare(strict_types=1);

namespace App\Shared\Http;

final class ApiProblemException extends \RuntimeException
{
    /**
     * @param list<array{pointer: string, detail: string}> $errors
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly int $statusCode,
        string $detail,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($detail);
    }

    public static function validation(string $detail, ?string $pointer = null): self
    {
        $errors = null === $pointer ? [] : [['pointer' => $pointer, 'detail' => $detail]];

        return new self('/errors/validation', 'Requête invalide', 400, $detail, $errors);
    }

    public static function authentication(string $detail = 'Authentification requise.'): self
    {
        return new self('/errors/authentication', 'Authentification requise', 401, $detail);
    }

    public static function accessDenied(string $detail = 'Accès refusé.'): self
    {
        return new self('/errors/access-denied', 'Accès refusé', 403, $detail);
    }

    public static function notFound(string $detail = 'Ressource introuvable.'): self
    {
        return new self('/errors/not-found', 'Ressource introuvable', 404, $detail);
    }

    public static function merchantSuspended(): self
    {
        return new self(
            '/errors/merchant-suspended',
            'Compte suspendu',
            403,
            'Un marchand suspendu ne peut pas accéder à l\'API.',
        );
    }

    public static function unavailable(): self
    {
        return new self(
            '/errors/unavailable',
            'Service indisponible',
            503,
            'La transaction n\'a pas pu être relue. Réessayez.',
            [],
            1,
        );
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            '/errors/idempotency-conflict',
            'Conflit d\'idempotence',
            409,
            'Cette clé d\'idempotence a déjà été utilisée avec un autre contenu.',
        );
    }

    public static function invalidTransition(): self
    {
        return new self(
            '/errors/invalid-transition',
            'Transition invalide',
            422,
            'Cette transition de statut n\'est pas autorisée.',
        );
    }

    public static function apiKeyLimit(): self
    {
        return new self(
            '/errors/api-key-limit',
            'Nombre maximal de clés atteint',
            409,
            'Un marchand ne peut pas avoir plus de 5 clés actives.',
        );
    }
}
