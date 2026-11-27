<?php

declare(strict_types=1);

namespace App\Payment\Domain\Entity;

use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Infrastructure\Repository\TransactionEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionEventRepository::class)]
#[ORM\Table(name: 'transaction_event')]
class TransactionEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Transaction $transaction;

    #[ORM\Column(enumType: TransactionStatus::class, length: 32)]
    private TransactionStatus $fromStatus;

    #[ORM\Column(enumType: TransactionStatus::class, length: 32)]
    private TransactionStatus $toStatus;

    #[ORM\Column(length: 255)]
    private string $reason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        Transaction $transaction,
        TransactionStatus $fromStatus,
        TransactionStatus $toStatus,
        string $reason,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->transaction = $transaction;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->reason = $reason;
        $this->createdAt = $createdAt;
    }

    public static function record(
        Transaction $transaction,
        TransactionStatus $fromStatus,
        TransactionStatus $toStatus,
        string $reason,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($transaction, $fromStatus, $toStatus, $reason, $createdAt);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    public function getFromStatus(): TransactionStatus
    {
        return $this->fromStatus;
    }

    public function getToStatus(): TransactionStatus
    {
        return $this->toStatus;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
