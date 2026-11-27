<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121205533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables transaction et transaction_event.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction (id BINARY(16) NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(32) NOT NULL, phone VARCHAR(16) NOT NULL, provider VARCHAR(16) NOT NULL, merchant_reference VARCHAR(64) NOT NULL, description VARCHAR(255) DEFAULT NULL, idempotency_key VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, failure_reason VARCHAR(255) DEFAULT NULL, idempotency_response_status SMALLINT NOT NULL, idempotency_response_body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, merchant_id BINARY(16) NOT NULL, INDEX idx_transaction_merchant_status_created (merchant_id, status, created_at), UNIQUE INDEX uniq_transaction_merchant_idempotency (merchant_id, idempotency_key), INDEX IDX_723705D16796D554 (merchant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transaction_event (id BINARY(16) NOT NULL, from_status VARCHAR(32) NOT NULL, to_status VARCHAR(32) NOT NULL, reason VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, transaction_id BINARY(16) NOT NULL, INDEX IDX_9A9DDAE72FC0CB0F (transaction_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D16796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_event ADD CONSTRAINT FK_9A9DDAE72FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D16796D554');
        $this->addSql('ALTER TABLE transaction_event DROP FOREIGN KEY FK_9A9DDAE72FC0CB0F');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('DROP TABLE transaction_event');
    }
}
