<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Controller;

use App\Merchant\Domain\Model\UpdateMerchantProfileData;
use App\Merchant\Domain\Service\UpdateMerchantProfile;
use App\Merchant\UserInterface\MerchantPresenter;
use App\Merchant\UserInterface\Security\CurrentMerchant;
use App\Merchant\UserInterface\Security\JwtMerchant;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController
{
    public function __construct(
        private readonly CurrentMerchant $currentMerchant,
        private readonly JwtMerchant $jwtMerchant,
        private readonly UpdateMerchantProfile $updateMerchantProfile,
        private readonly MerchantPresenter $presenter,
    ) {
    }

    #[Route('/api/v1/me', name: 'api_v1_me', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse($this->presenter->merchant($this->currentMerchant->merchant()));
    }

    #[Route('/api/v1/me', name: 'api_v1_me_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $merchant = $this->jwtMerchant->merchant();
        $payload = JsonBody::decode($request, ['name', 'password', 'current_password']);
        $data = new UpdateMerchantProfileData(
            $this->optional($payload, 'name'),
            $this->optional($payload, 'password'),
            $this->optional($payload, 'current_password'),
        );

        return new JsonResponse($this->presenter->merchant($this->updateMerchantProfile->execute($merchant, $data)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optional(array $payload, string $field): ?string
    {
        if (!\array_key_exists($field, $payload) || null === $payload[$field]) {
            return null;
        }
        if (!\is_string($payload[$field])) {
            throw ApiProblemException::validation('Valeur invalide.', '/'.$field);
        }

        return 'name' === $field ? trim($payload[$field]) : $payload[$field];
    }
}
