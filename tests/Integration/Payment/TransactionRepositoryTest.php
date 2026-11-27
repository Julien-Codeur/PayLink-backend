<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Merchant\Domain\Entity\Merchant;
use App\Payment\Domain\Entity\Transaction;
use App\Payment\Domain\Enum\ProviderCode;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class TransactionRepositoryTest extends ApiTestCase
{
    public function testLeMontantEstUnEntierEtLaCleEstUnique(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $merchant = Merchant::register('Boutique', bin2hex(random_bytes(6)).'@example.test', 'hash', new \DateTimeImmutable());
        $entityManager->persist($merchant);
        $key = 'idem-key-unique1';
        $transaction = Transaction::initiate(
            $merchant,
            5000,
            '+22890123452',
            ProviderCode::MtnSim,
            'CMD-1',
            null,
            $key,
            hash('sha256', 'corps'),
            new \DateTimeImmutable(),
        );
        $entityManager->persist($transaction);
        $entityManager->flush();
        $transactionId = $transaction->getId();
        $merchantId = $merchant->getId();
        $entityManager->clear();

        $reloaded = $entityManager->find(Transaction::class, $transactionId);
        self::assertInstanceOf(Transaction::class, $reloaded);
        self::assertSame(5000, $reloaded->getAmount());

        $metadata = $entityManager->getClassMetadata(Transaction::class);
        self::assertSame('integer', $metadata->getTypeOfField('amount'));
        foreach ($metadata->getFieldNames() as $field) {
            self::assertNotContains($metadata->getTypeOfField($field), ['float', 'decimal', 'double']);
        }

        $managedMerchant = $entityManager->find(Merchant::class, $merchantId);
        self::assertInstanceOf(Merchant::class, $managedMerchant);
        $duplicate = Transaction::initiate(
            $managedMerchant,
            1500,
            '+22890123452',
            ProviderCode::MoovSim,
            'CMD-2',
            null,
            $key,
            hash('sha256', 'autre'),
            new \DateTimeImmutable(),
        );
        $entityManager->persist($duplicate);

        try {
            $entityManager->flush();
            self::fail('La contrainte unique aurait dû refuser la seconde ligne.');
        } catch (UniqueConstraintViolationException) {
            self::ensureKernelShutdown();
        }
    }
}
