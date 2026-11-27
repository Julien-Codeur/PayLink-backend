<?php

declare(strict_types=1);

namespace App\Payment\UserInterface\Controller;

use App\Merchant\UserInterface\Security\CurrentMerchant;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Service\TransactionResponseFactory;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Payment\UserInterface\TransactionListQuery;
use App\Shared\Http\ApiProblemException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/transactions')]
final class TransactionController
{
    public function __construct(
        private readonly CurrentMerchant $currentMerchant,
        private readonly TransactionRepository $transactions,
        private readonly TransactionEventRepository $events,
        private readonly TransactionResponseFactory $responses,
        private readonly TransactionListQuery $listQuery,
    ) {
    }

    #[Route('', name: 'api_v1_transactions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $merchant = $this->currentMerchant->merchant();
        $criteria = $this->listQuery->fromRequest($request);
        $page = $this->transactions->search($merchant, $criteria);
        $items = [];
        foreach ($page->items as $transaction) {
            $items[] = $this->responses->summary($transaction);
        }

        return new JsonResponse([
            'items' => $items,
            'page' => $criteria->page,
            'items_per_page' => $criteria->perPage,
            'total' => $page->total,
        ]);
    }

    #[Route('/{id}', name: 'api_v1_transactions_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $merchant = $this->currentMerchant->merchant();
        $transaction = $this->transactions->findOneForMerchant($merchant, $id);
        if (!$transaction instanceof Transaction) {
            throw ApiProblemException::notFound();
        }

        return new JsonResponse($this->responses->detail($transaction, $this->events->findForTransaction($transaction)));
    }
}
