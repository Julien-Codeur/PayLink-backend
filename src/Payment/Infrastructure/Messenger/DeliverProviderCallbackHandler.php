<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Messenger;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Message\DeliverProviderCallback;
use App\Payment\Domain\Service\OperatorSimulator;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class DeliverProviderCallbackHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly OperatorSimulator $simulator,
        private readonly HttpKernelInterface $kernel,
        private readonly LoggerInterface $logger,
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

        try {
            $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
        } catch (\Throwable $exception) {
            throw new RecoverableMessageHandlingException('Le callback fournisseur est injoignable.', 0, $exception);
        }

        $status = $response->getStatusCode();
        if (\in_array($status, [401, 403], true)) {
            throw new UnrecoverableMessageHandlingException('Le callback fournisseur a été refusé.');
        }
        if (422 === $status) {
            $this->logger->warning('Callback fournisseur incohérent.', [
                'transaction_id' => $message->transactionId,
                'status_code' => $status,
            ]);

            return;
        }
        if (429 === $status || $status >= 500) {
            throw new RecoverableMessageHandlingException('Le callback fournisseur doit être rejoué.');
        }
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
    }
}
