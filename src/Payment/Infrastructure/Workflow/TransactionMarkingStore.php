<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Workflow;

use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\TransactionStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

final class TransactionMarkingStore implements MarkingStoreInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function getMarking(object $subject): Marking
    {
        if (!$subject instanceof Transaction) {
            throw new \LogicException('Sujet de transaction inattendu.');
        }

        return new Marking([$subject->getStatus()->value => 1]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        if (!$subject instanceof Transaction) {
            throw new \LogicException('Sujet de transaction inattendu.');
        }

        $places = array_keys($marking->getPlaces());
        $status = TransactionStatus::tryFrom($places[0] ?? '');
        if (!$status instanceof TransactionStatus) {
            throw new \LogicException('Place de transaction inconnue.');
        }

        $at = $context['at'] ?? $this->clock->now();
        if (!$at instanceof \DateTimeImmutable) {
            throw new \LogicException('Horodatage de transition invalide.');
        }

        $move = \Closure::bind(
            static function (Transaction $transaction, TransactionStatus $status, \DateTimeImmutable $at): void {
                $transaction->moveTo($status, $at);
            },
            null,
            Transaction::class,
        );
        if (null === $move) { // @phpstan-ignore identical.alwaysFalse
            throw new \LogicException('Le changement de statut est inaccessible.');
        }

        $move($subject, $status, $at);
    }
}
