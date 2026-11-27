<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Messenger;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Message\DeliverProviderCallback;
use App\Payment\Domain\Service\OperatorSimulator;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class DeliverProviderCallbackHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly OperatorSimulator $simulator,
        private readonly HttpKernelInterface $kernel,
        #[Autowire('%env(PAYLINK_PROVIDER_CALLBACK_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    public function __invoke(DeliverProviderCallback $message): void
    {
        if (!Uuid::isValid($message->transactionId)) {
            return;
        }

        $transaction = $this->transactions->find(Uuid::fromString($message->transactionId));
        if (!$transaction instanceof Transaction) {
            return;
        }

        $decision = $this->simulator->decide($transaction->getPhone());
        if (!$decision->notifies) {
            return;
        }

        $payload = [
            'transaction_id' => $message->transactionId,
            'outcome' => $decision->success ? 'succeed' : 'fail',
        ];
        if (!$decision->success && null !== $decision->failureCode) {
            $payload['failure_reason'] = $decision->failureCode;
        }

        $request = Request::create(
            '/internal/provider/callback',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PROVIDER_SECRET' => $this->secret,
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
        if ($response->getStatusCode() >= 500) {
            throw new \RuntimeException('Le callback fournisseur a échoué.');
        }
    }
}
