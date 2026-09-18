<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Агрегат отчёта (context Reports): общая шапка в колонках + content jsonb под блок-специфику.
 * id — uuid, ссылки на пользователя (owner/reviewer) — ulid-строки; type_key nullable (отчёт без
 * пресета — Д4). Идемпотентно: CREATE IF NOT EXISTS.
 */
final class Version20260918150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create report aggregate table (header columns + jsonb content).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS report (
                id UUID NOT NULL,
                owner_id VARCHAR(26) NOT NULL,
                reviewer_id VARCHAR(26) DEFAULT NULL,
                type_key VARCHAR(30) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                report_date DATE DEFAULT NULL,
                act_number VARCHAR(100) DEFAULT NULL,
                content JSONB NOT NULL DEFAULT '{}',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_owner ON report (owner_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_status ON report (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_type ON report (type_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS report');
    }
}
