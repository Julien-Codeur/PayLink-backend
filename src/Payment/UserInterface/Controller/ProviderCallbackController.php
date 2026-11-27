<?php

declare(strict_types=1);

namespace App\Payment\UserInterface\Controller;

use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Service\SettlePayment;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ProviderCallbackController
{
    public function __construct(
        private readonly SettlePayment $settle,
        #[Autowire('%env(PAYLINK_PROVIDER_CALLBACK_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    #[Route('/internal/provider/callback', name: 'internal_provider_callback', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $this->assertSecret($request);
        $data = JsonBody::decode($request, ['transaction_id', 'outcome', 'failure_reason']);
        $transactionId = JsonBody::requiredString($data, 'transaction_id');
        if (!Uuid::isValid($transactionId)) {
            throw ApiProblemException::validation('Identifiant invalide.', '/transaction_id');
        }

        $outcome = JsonBody::requiredString($data, 'outcome');
        if (!\in_array($outcome, ['succeed', 'fail'], true)) {
            throw ApiProblemException::validation('Résultat invalide.', '/outcome');
        }

        $failureCode = JsonBody::optionalString($data, 'failure_reason');
        if ('fail' === $outcome) {
            if (!\in_array($failureCode, [FailureCode::InsufficientFunds->value, FailureCode::Timeout->value], true)) {
                throw ApiProblemException::validation('Cause d\'échec invalide.', '/failure_reason');
            }
        } elseif (null !== $failureCode) {
            throw ApiProblemException::validation('Un succès n\'a pas de cause d\'échec.', '/failure_reason');
        }

        $this->settle->callback(Uuid::fromString($transactionId), $outcome, $failureCode);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function assertSecret(Request $request): void
    {
        $presented = $request->headers->get('X-Provider-Secret', '');
        if (!$this->matches($presented)) {
            throw ApiProblemException::authentication('Secret fournisseur invalide.');
        }
    }

    private function matches(string $presented): bool
    {
        if ('' === $this->secret || \strlen($presented) !== \strlen($this->secret)) {
            return false;
        }

        return hash_equals($this->secret, $presented);
    }
}
