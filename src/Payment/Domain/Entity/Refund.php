<?php

declare(strict_types=1);

namespace App\Payment\Domain\Entity;

use App\Payment\Domain\Enum\RefundStatus;
use App\Payment\Infrastructure\Repository\RefundRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RefundRepository::class)]
#[ORM\Table(name: 'refund')]
#[ORM\UniqueConstraint(name: 'uniq_refund_transaction_idempotency', columns: ['transaction_id', 'idempotency_key'])]
class Refund
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Transaction $transaction;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(enumType: RefundStatus::class, length: 32)]
    private RefundStatus $status;

    #[ORM\Column(length: 64)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(type: 'smallint')]
    private int $idempotencyResponseStatus = 201;

    #[ORM\Column(type: 'text')]
    private string $idempotencyResponseBody = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        Transaction $transaction,
        int $amount,
        string $idempotencyKey,
        string $requestHash,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->transaction = $transaction;
        $this->amount = $amount;
        $this->status = RefundStatus::Succeeded;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
        $this->createdAt = $createdAt;
    }

    public static function succeed(
        Transaction $transaction,
        int $amount,
        string $idempotencyKey,
        string $requestHash,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($transaction, $amount, $idempotencyKey, $requestHash, $createdAt);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getStatus(): RefundStatus
    {
        return $this->status;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function getIdempotencyResponseStatus(): int
    {
        return $this->idempotencyResponseStatus;
    }

    public function getIdempotencyResponseBody(): string
    {
        return $this->idempotencyResponseBody;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rememberResponse(int $status, string $body): void
    {
        $this->idempotencyResponseStatus = $status;
        $this->idempotencyResponseBody = $body;
    }
}
