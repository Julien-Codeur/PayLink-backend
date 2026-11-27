<?php

declare(strict_types=1);

namespace App\Payment\UserInterface\Controller;

use App\Merchant\UserInterface\Security\ApiKeyMerchant;
use App\Payment\Domain\Message\ProcessPayment;
use App\Payment\Domain\Service\CreateTransaction;
use App\Payment\UserInterface\TransactionRequestParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CreateTransactionController
{
    public function __construct(
        private readonly ApiKeyMerchant $apiKeyMerchant,
        private readonly TransactionRequestParser $parser,
        private readonly CreateTransaction $createTransaction,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $merchant = $this->apiKeyMerchant->merchant();
        $result = $this->createTransaction->execute($merchant, $this->parser->parse($request));
        if (!$result->replayed) {
            $this->bus->dispatch(new ProcessPayment($result->transactionId));
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($result->replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return new Response($result->body, $result->statusCode, $headers);
    }
}
