<?php

declare(strict_types=1);

namespace App\Merchant\UserInterface\Controller;

use App\Merchant\Domain\Model\RegisterMerchantData;
use App\Merchant\Domain\Service\RegisterMerchant;
use App\Merchant\UserInterface\MerchantPresenter;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use App\Shared\Validation\RequestValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController
{
    public function __construct(
        private readonly RequestValidator $validator,
        private readonly RegisterMerchant $registerMerchant,
        private readonly MerchantPresenter $presenter,
    ) {
    }

    #[Route('/api/v1/auth/register', name: 'api_v1_auth_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = JsonBody::decode($request, ['name', 'email', 'password']);
        $data = new RegisterMerchantData(
            trim($this->string($payload, 'name')),
            mb_strtolower(trim($this->string($payload, 'email'))),
            $this->string($payload, 'password'),
        );
        $this->validator->validate($data);
        $merchant = $this->registerMerchant->execute($data->name, $data->email, $data->password);

        return new JsonResponse($this->presenter->merchant($merchant), Response::HTTP_CREATED);
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
