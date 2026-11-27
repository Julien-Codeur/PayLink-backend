<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Provider;

use App\Payment\Domain\Contract\PaymentProvider;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Message\DeliverProviderCallback;
use App\Payment\Domain\Service\OperatorSimulator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class CallbackSchedulingProvider implements PaymentProvider
{
    public function __construct(
        private readonly OperatorSimulator $simulator,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function request(Transaction $transaction): void
    {
        $decision = $this->simulator->decide($transaction->getPhone());
        if (!$decision->notifies) {
            return;
        }

        $stamps = [];
        if ($decision->delaySeconds > 0) {
            $stamps[] = new DelayStamp($decision->delaySeconds * 1000);
        }

        $this->bus->dispatch(new DeliverProviderCallback((string) $transaction->getId()), $stamps);
    }
}
