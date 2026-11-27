<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121202325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables merchant, api_key et refresh_token.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_key (id BINARY(16) NOT NULL, prefix VARCHAR(8) NOT NULL, key_hash VARCHAR(64) NOT NULL, active TINYINT NOT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, merchant_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_C912ED9D57BFB971 (key_hash), INDEX IDX_C912ED9D6796D554 (merchant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE merchant (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_74AB25E1E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_token (id BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, merchant_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_C74F2195B3BC57DA (token_hash), INDEX IDX_C74F21956796D554 (merchant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_key ADD CONSTRAINT FK_C912ED9D6796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_C74F21956796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_key DROP FOREIGN KEY FK_C912ED9D6796D554');
        $this->addSql('ALTER TABLE refresh_token DROP FOREIGN KEY FK_C74F21956796D554');
        $this->addSql('DROP TABLE api_key');
        $this->addSql('DROP TABLE merchant');
        $this->addSql('DROP TABLE refresh_token');
    }
}
