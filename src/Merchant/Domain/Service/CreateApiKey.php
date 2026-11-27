<?php

declare(strict_types=1);

namespace App\Merchant\Domain\Service;

use App\Merchant\Domain\Entity\ApiKey;
use App\Merchant\Domain\Entity\Merchant;
use App\Merchant\Domain\Model\IssuedApiKey;
use App\Merchant\Infrastructure\Repository\ApiKeyRepository;
use App\Shared\Http\ApiProblemException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class CreateApiKey
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly ApiKeyGenerator $generator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(Merchant $merchant): IssuedApiKey
    {
        return $this->entityManager->wrapInTransaction(function () use ($merchant): IssuedApiKey {
            $locked = $this->entityManager->find(Merchant::class, $merchant->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof Merchant) {
                throw ApiProblemException::notFound('Marchand introuvable.');
            }

            if ($this->apiKeys->countActiveFor($locked) >= 5) {
                throw ApiProblemException::apiKeyLimit();
            }

            $generated = $this->generator->generate();
            $apiKey = new ApiKey($locked, $generated->prefix, $generated->keyHash, $this->clock->now());
            $this->entityManager->persist($apiKey);

            return new IssuedApiKey($apiKey, $generated->plainKey);
        });
    }
}
