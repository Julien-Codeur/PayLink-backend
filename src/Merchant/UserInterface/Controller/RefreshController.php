<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Controller;

use App\Merchant\Domain\Service\RefreshMerchantSession;
use App\Shared\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class RefreshController
{
    public function __construct(private readonly RefreshMerchantSession $refreshMerchantSession)
    {
    }

    #[Route('/api/v1/auth/refresh', name: 'api_v1_auth_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = JsonBody::decode($request, ['refresh_token']);
        $tokens = $this->refreshMerchantSession->execute(JsonBody::requiredString($payload, 'refresh_token'));

        return new JsonResponse([
            'token' => $tokens->token,
            'refresh_token' => $tokens->refreshToken,
        ]);
    }
}
