<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250920160547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_channel (id UUID NOT NULL, owner_id VARCHAR(26) NOT NULL, type VARCHAR(100) NOT NULL, value VARCHAR(255) NOT NULL, is_verified BOOLEAN NOT NULL, verified_at  TIMESTAMP(0) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FAF4904D7E3C61F9 ON user_channel (owner_id)');
        $this->addSql('COMMENT ON COLUMN user_channel.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_channel.verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE user_channel ADD CONSTRAINT FK_FAF4904D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user_user (ulid) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_channel DROP CONSTRAINT FK_FAF4904D7E3C61F9');
        $this->addSql('DROP TABLE user_channel');
    }
}
