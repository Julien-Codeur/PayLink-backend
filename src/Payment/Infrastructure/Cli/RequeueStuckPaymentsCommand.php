<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Cli;

use App\Payment\Domain\Enum\FailureCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Domain\Message\ProcessPayment;
use App\Payment\Domain\Service\TransactionLifecycle;
use App\Payment\Domain\Service\TransactionTransition;
use App\Payment\Domain\Service\TransitionReason;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use App\Shared\Http\ApiProblemException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:payments:requeue-stuck',
    description: 'Republie les paiements initiés trop longtemps et expire ceux restés en attente.',
)]
final class RequeueStuckPaymentsCommand extends Command
{
    private const BATCH = 100;
    private const INITIATED_AFTER_SECONDS = 30;
    private const PENDING_AFTER_SECONDS = 70;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly TransactionLifecycle $lifecycle,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $republished = 0;
        foreach ($this->transactions->findInitiatedCreatedBefore($now->modify(\sprintf('-%d seconds', self::INITIATED_AFTER_SECONDS)), self::BATCH) as $transaction) {
            $this->bus->dispatch(new ProcessPayment((string) $transaction->getId()));
            ++$republished;
        }

        $expired = 0;
        foreach ($this->transactions->findPendingMovedBefore($now->modify(\sprintf('-%d seconds', self::PENDING_AFTER_SECONDS)), self::BATCH) as $transaction) {
            if (TransactionStatus::Pending !== $transaction->getStatus()) {
                continue;
            }

            try {
                $this->lifecycle->apply(
                    $transaction,
                    TransactionTransition::FAIL,
                    TransitionReason::TIMEOUT,
                    FailureCode::Timeout->value,
                );
                ++$expired;
            } catch (ApiProblemException $exception) {
                if ('/errors/invalid-transition' !== $exception->type) {
                    throw $exception;
                }
            }
        }

        $output->writeln(\sprintf('Republiés : %d. Expirés : %d.', $republished, $expired));

        return Command::SUCCESS;
    }
}
