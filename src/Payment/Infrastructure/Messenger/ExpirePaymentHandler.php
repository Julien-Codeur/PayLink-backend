<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Messenger;

use App\Payment\Domain\Message\ExpirePayment;
use App\Payment\Domain\Service\SettlePayment;
use App\Shared\Http\ApiProblemException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ExpirePaymentHandler
{
    public function __construct(private readonly SettlePayment $settle)
    {
    }

    public function __invoke(ExpirePayment $message): void
    {
        if (!Uuid::isValid($message->transactionId)) {
            return;
        }

        try {
            $this->settle->expire(Uuid::fromString($message->transactionId));
        } catch (ApiProblemException $exception) {
            if (404 === $exception->statusCode) {
                return;
            }

            throw $exception;
        }
    }
}
