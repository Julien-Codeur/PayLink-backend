<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Workflow;

use App\Payment\Domain\Entity\Transaction;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

final class RefundGuard
{
    #[AsEventListener(event: 'workflow.transaction.guard.refund_partial')]
    public function guardPartial(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof Transaction || !$subject->acceptsPartialRefund()) {
            $event->setBlocked(true, 'Remboursement partiel refusé.');
        }
    }

    #[AsEventListener(event: 'workflow.transaction.guard.refund_full')]
    public function guardFull(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof Transaction || !$subject->acceptsFullRefund()) {
            $event->setBlocked(true, 'Remboursement total refusé.');
        }
    }
}
