<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261127012824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table refund.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE refund (
              id BINARY(16) NOT NULL,
              amount INT NOT NULL,
              status VARCHAR(32) NOT NULL,
              idempotency_key VARCHAR(64) NOT NULL,
              request_hash VARCHAR(64) NOT NULL,
              idempotency_response_status SMALLINT NOT NULL,
              idempotency_response_body LONGTEXT NOT NULL,
              created_at DATETIME NOT NULL,
              transaction_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_refund_transaction_idempotency (transaction_id, idempotency_key),
              INDEX IDX_5B2C14582FC0CB0F (transaction_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              refund
            ADD
              CONSTRAINT FK_5B2C14582FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE refund DROP FOREIGN KEY FK_5B2C14582FC0CB0F');
        $this->addSql('DROP TABLE refund');
    }
}
