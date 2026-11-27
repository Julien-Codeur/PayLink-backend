<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Entity;

use App\Merchant\Domain\Enum\MerchantStatus;
use App\Merchant\Infrastructure\Repository\MerchantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MerchantRepository::class)]
#[ORM\Table(name: 'merchant')]
class Merchant implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(enumType: MerchantStatus::class, length: 32)]
    private MerchantStatus $status;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct(string $name, string $email, string $passwordHash, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->email = $email;
        $this->password = $passwordHash;
        $this->status = MerchantStatus::Active;
        $this->roles = ['ROLE_MERCHANT'];
        $this->createdAt = $createdAt;
    }

    public static function register(string $name, string $email, string $passwordHash, \DateTimeImmutable $createdAt): self
    {
        return new self($name, $email, $passwordHash, $createdAt);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function changePassword(string $passwordHash): void
    {
        $this->password = $passwordHash;
    }

    public function getStatus(): MerchantStatus
    {
        return $this->status;
    }

    public function suspend(): void
    {
        $this->status = MerchantStatus::Suspended;
    }

    public function isSuspended(): bool
    {
        return MerchantStatus::Suspended === $this->status;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_MERCHANT']));
    }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
