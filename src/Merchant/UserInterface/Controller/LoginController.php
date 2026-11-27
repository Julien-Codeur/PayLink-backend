<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Controller;

use App\Merchant\Domain\Service\LoginMerchant;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LoginController
{
    public function __construct(private readonly LoginMerchant $loginMerchant)
    {
    }

    #[Route('/api/v1/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = JsonBody::decode($request, ['email', 'password']);
        $email = mb_strtolower(trim($this->string($payload, 'email')));
        $password = $this->string($payload, 'password');
        if ('' === $email || '' === $password) {
            throw ApiProblemException::validation('L\'e-mail et le mot de passe sont obligatoires.');
        }

        $tokens = $this->loginMerchant->execute($email, $password);

        return new JsonResponse([
            'token' => $tokens->token,
            'refresh_token' => $tokens->refreshToken,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function string(array $payload, string $field): string
    {
        if (!\array_key_exists($field, $payload) || null === $payload[$field]) {
            return '';
        }
        if (!\is_string($payload[$field])) {
            throw ApiProblemException::validation('Valeur invalide.', '/'.$field);
        }

        return $payload[$field];
    }
}
