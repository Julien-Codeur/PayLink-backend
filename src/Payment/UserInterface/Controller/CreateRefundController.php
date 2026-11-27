<?php

declare(strict_types=1);

namespace App\Payment\UserInterface\Controller;

use App\Merchant\UserInterface\Security\CurrentMerchant;
use App\Payment\Domain\Service\CreateRefund;
use App\Payment\UserInterface\RefundRequestParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CreateRefundController
{
    public function __construct(
        private readonly CurrentMerchant $currentMerchant,
        private readonly RefundRequestParser $parser,
        private readonly CreateRefund $createRefund,
    ) {
    }

    #[Route('/api/v1/transactions/{id}/refunds', name: 'api_v1_transaction_refunds_create', methods: ['POST'])]
    public function create(Request $request, string $id): Response
    {
        $result = $this->createRefund->execute($this->currentMerchant->merchant(), $id, $this->parser->parse($request));
        $headers = ['Content-Type' => 'application/json'];
        if ($result->replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return new Response($result->body, $result->statusCode, $headers);
    }
}
