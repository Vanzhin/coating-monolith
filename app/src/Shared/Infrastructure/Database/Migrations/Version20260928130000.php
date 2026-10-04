<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Иерархический справочник отделов (context Personnel): self-parent дерево, привязанное
 * к компании (Reports/Counterparty). Идемпотентно: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20260928130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create personnel_department hierarchical directory table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS personnel_department (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(150) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                head_user_ulid VARCHAR(26) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_personnel_department_company ON personnel_department (company_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_personnel_department_parent ON personnel_department (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS personnel_department');
    }
}
