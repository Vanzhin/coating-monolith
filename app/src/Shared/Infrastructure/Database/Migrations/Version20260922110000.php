<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Агрегат отчёта (context Reports) — КОНСОЛИДИРОВАННАЯ схема. Объединяет прежнюю цепочку миграций
 * report в одну до первого прод-деплоя: базовая таблица + снимки-ссылки (project/customer/contractor/
 * system как jsonb) + address/work_period + rejection_reason. Идемпотентно и безопасно как на свежей БД
 * (CREATE), так и на частично мигрированной (ADD COLUMN/INDEX IF NOT EXISTS + ALTER TYPE jsonb сходятся).
 *
 * Ссылки/период — jsonb (кириллица сырым UTF-8, не \uXXXX; индексируем). owner/reviewer — ulid-строки,
 * id отчёта — uuid, type_key nullable (отчёт без пресета — Д4).
 */
final class Version20260922110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report aggregate: consolidated schema (table + jsonb refs + address/work_period + rejection_reason).';
    }

    public function up(Schema $schema): void
    {
        // Свежая БД — вся таблица разом.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS report (
                id UUID NOT NULL,
                owner_id VARCHAR(26) NOT NULL,
                reviewer_id VARCHAR(26) DEFAULT NULL,
                rejection_reason TEXT DEFAULT NULL,
                type_key VARCHAR(30) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                report_date DATE DEFAULT NULL,
                act_number VARCHAR(100) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                work_period JSONB DEFAULT NULL,
                project JSONB DEFAULT NULL,
                customer JSONB DEFAULT NULL,
                contractor JSONB DEFAULT NULL,
                system JSONB DEFAULT NULL,
                content JSONB NOT NULL DEFAULT '{}',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
        SQL);

        // Частично мигрированная БД (таблица из прежней цепочки) — доводим до финальной схемы.
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS work_period JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS project JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS customer JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS contractor JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE report ADD COLUMN IF NOT EXISTS system JSONB DEFAULT NULL');
        foreach (['project', 'customer', 'contractor', 'system', 'work_period'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report ALTER COLUMN %1$s TYPE jsonb USING %1$s::jsonb', $column));
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_owner ON report (owner_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_status ON report (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_type ON report (type_key)');
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_project ON report ((project->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_customer ON report ((customer->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_contractor ON report ((contractor->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_system ON report ((system->>'id'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS report');
    }
}
