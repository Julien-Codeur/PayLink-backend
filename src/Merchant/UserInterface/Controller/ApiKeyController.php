<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Controller;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Domain\Service\CreateApiKey;
use App\Merchant\Domain\Service\RevokeApiKey;
use App\Merchant\Infrastructure\Repository\ApiKeyRepository;
use App\Merchant\UserInterface\MerchantPresenter;
use App\Merchant\UserInterface\Security\JwtMerchant;
use App\Merchant\UserInterface\Security\MerchantVoter;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/api-keys')]
final class ApiKeyController
{
    public function __construct(
        private readonly JwtMerchant $jwtMerchant,
        private readonly ApiKeyRepository $apiKeys,
        private readonly CreateApiKey $createApiKey,
        private readonly RevokeApiKey $revokeApiKey,
        private readonly MerchantPresenter $presenter,
        private readonly Security $security,
    ) {
    }

    #[Route('', name: 'api_v1_api_keys_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $merchant = $this->jwtMerchant->merchant();
        $items = [];
        foreach ($this->apiKeys->findByMerchant($merchant) as $apiKey) {
            $items[] = $this->presenter->apiKey($apiKey);
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('', name: 'api_v1_api_keys_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $merchant = $this->jwtMerchant->merchant();
        JsonBody::decode($request, []);
        $issued = $this->createApiKey->execute($merchant);

        return new JsonResponse($this->presenter->apiKey($issued->apiKey, $issued->plainKey), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_v1_api_keys_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse($this->presenter->apiKey($this->requireOwned($id, MerchantVoter::VIEW)));
    }

    #[Route('/{id}', name: 'api_v1_api_keys_revoke', methods: ['DELETE'])]
    public function revoke(string $id): Response
    {
        $this->revokeApiKey->execute($this->requireOwned($id, MerchantVoter::REVOKE));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function requireOwned(string $id, string $attribute): ApiKey
    {
        $this->jwtMerchant->merchant();
        $apiKey = $this->apiKeys->findOneById($id);
        if (!$apiKey instanceof ApiKey) {
            throw ApiProblemException::notFound();
        }
        if (!$this->security->isGranted($attribute, $apiKey)) {
            throw ApiProblemException::accessDenied();
        }

        return $apiKey;
    }
}
