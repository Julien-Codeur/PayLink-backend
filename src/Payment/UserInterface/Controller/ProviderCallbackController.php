<?php

declare(strict_types=1);

namespace App\Payment\UserInterface\Controller;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Service\OperatorSimulator;
use App\Payment\Domain\Service\SettlePayment;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Shared\Http\ApiProblemException;
use App\Shared\Http\JsonBody;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ProviderCallbackController
{
    public function __construct(
        private readonly SettlePayment $settle,
        private readonly TransactionRepository $transactions,
        private readonly OperatorSimulator $simulator,
        #[Autowire('%env(PAYLINK_PROVIDER_CALLBACK_SECRET)%')]
        private readonly string $secret,
        #[Autowire(service: 'limiter.provider_callback_auth')]
        private readonly RateLimiterFactory $authFailures,
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

        $transaction = $this->transactions->find(Uuid::fromString($transactionId));
        if (!$transaction instanceof Transaction) {
            throw ApiProblemException::notFound('Transaction introuvable.');
        }
        $this->assertExpectedOutcome($transaction, $outcome, $failureCode);
        $this->settle->callback($transaction->getId(), $outcome, $failureCode);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function assertSecret(Request $request): void
    {
        $presented = $request->headers->get('X-Provider-Secret', '');
        if ($this->matches($presented)) {
            return;
        }

        $ip = $request->getClientIp();
        $limit = $this->authFailures->create(\is_string($ip) && '' !== $ip ? $ip : 'unknown')->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            throw ApiProblemException::rateLimit($retryAfter);
        }

        throw ApiProblemException::authentication('Secret fournisseur invalide.');
    }

    private function matches(string $presented): bool
    {
        if ('' === $this->secret) {
            return false;
        }

        return hash_equals(hash('sha256', $this->secret), hash('sha256', $presented));
    }

    private function assertExpectedOutcome(Transaction $transaction, string $outcome, ?string $failureCode): void
    {
        $decision = $this->simulator->decide($transaction->getPhone());
        if (!$decision->notifies) {
            throw ApiProblemException::invalidTransition();
        }

        $expected = $decision->success ? 'succeed' : 'fail';
        if ($outcome !== $expected || $failureCode !== $decision->failureCode) {
            throw ApiProblemException::invalidTransition();
        }
    }
}
