<?php

declare(strict_types=1);

namespace App\Payment\Domain\Entity;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Enum\ProviderCode;
use App\Payment\Domain\Enum\TransactionStatus;
use App\Payment\Infrastructure\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transaction')]
#[ORM\UniqueConstraint(name: 'uniq_transaction_merchant_idempotency', columns: ['merchant_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_transaction_merchant_status_created', columns: ['merchant_id', 'status', 'created_at'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Merchant $merchant;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(enumType: TransactionStatus::class, length: 32)]
    private TransactionStatus $status;

    #[ORM\Column(length: 16)]
    private string $phone;

    #[ORM\Column(enumType: ProviderCode::class, length: 16)]
    private ProviderCode $provider;

    #[ORM\Column(length: 64)]
    private string $merchantReference;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description;

    #[ORM\Column(length: 64)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'smallint')]
    private int $idempotencyResponseStatus = 201;

    #[ORM\Column(type: 'text')]
    private string $idempotencyResponseBody = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1; // @phpstan-ignore property.onlyWritten

    private ?int $acceptedRefundTotal = null;

    private ?int $requestedRefundAmount = null;

    private function __construct(
        Merchant $merchant,
        int $amount,
        string $phone,
        ProviderCode $provider,
        string $merchantReference,
        ?string $description,
        string $idempotencyKey,
        string $requestHash,
        \DateTimeImmutable $at,
    ) {
        $this->id = Uuid::v7();
        $this->merchant = $merchant;
        $this->amount = $amount;
        $this->currency = 'XOF';
        $this->status = TransactionStatus::Initiated;
        $this->phone = $phone;
        $this->provider = $provider;
        $this->merchantReference = $merchantReference;
        $this->description = $description;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestHash = $requestHash;
        $this->createdAt = $at;
        $this->updatedAt = $at;
    }

    public static function initiate(
        Merchant $merchant,
        int $amount,
        string $phone,
        ProviderCode $provider,
        string $merchantReference,
        ?string $description,
        string $idempotencyKey,
        string $requestHash,
        \DateTimeImmutable $at,
    ): self {
        return new self($merchant, $amount, $phone, $provider, $merchantReference, $description, $idempotencyKey, $requestHash, $at);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getProvider(): ProviderCode
    {
        return $this->provider;
    }

    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rememberResponse(int $status, string $body): void
    {
        $this->idempotencyResponseStatus = $status;
        $this->idempotencyResponseBody = $body;
    }

    public function recordFailure(string $reason): void
    {
        $this->failureReason = $reason;
    }

    public function prepareRefund(int $acceptedTotal, int $requested): void
    {
        $this->acceptedRefundTotal = $acceptedTotal;
        $this->requestedRefundAmount = $requested;
    }

    public function acceptsPartialRefund(): bool
    {
        if (null === $this->acceptedRefundTotal || null === $this->requestedRefundAmount) {
            return false;
        }

        return $this->requestedRefundAmount >= 1
            && $this->acceptedRefundTotal + $this->requestedRefundAmount < $this->amount;
    }

    public function acceptsFullRefund(): bool
    {
        if (null === $this->acceptedRefundTotal || null === $this->requestedRefundAmount) {
            return false;
        }

        return $this->requestedRefundAmount >= 1
            && $this->acceptedRefundTotal + $this->requestedRefundAmount === $this->amount;
    }

    /** @phpstan-ignore method.unused */
    private function moveTo(TransactionStatus $status, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->updatedAt = $at;
    }
}
