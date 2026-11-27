<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Entity;

use App\Merchant\Infrastructure\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_key')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Merchant $merchant;

    #[ORM\Column(length: 8)]
    private string $prefix;

    #[ORM\Column(length: 64, unique: true)]
    private string $keyHash;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Merchant $merchant, string $prefix, string $keyHash, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->merchant = $merchant;
        $this->prefix = $prefix;
        $this->keyHash = $keyHash;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function revoke(): void
    {
        $this->active = false;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $usedAt): void
    {
        $this->lastUsedAt = $usedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
