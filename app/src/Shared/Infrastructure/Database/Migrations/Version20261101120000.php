<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: выноc проекции мониторинга из агрегата `ProfileCompliance` (expand/contract, шаг 1 —
 * expand). Новая read-model таблица `compliance_obligation_projection` — денормализованная строка на
 * (profile_id, requirement_id, item_id), пишет её DBAL-upsert'ом проектор (следующая задача), читает
 * read-only ORM-сущность `ObligationProjection`. Таблица аддитивна и пуста до проектора — существующую
 * старую проекцию/`ProfileCompliance` не трогаем. Идемпотентно.
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: новая read-model таблица compliance_obligation_projection (вынос проекции мониторинга, шаг expand).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_obligation_projection (
                id UUID NOT NULL,
                profile_id VARCHAR(64) NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                item_id VARCHAR(64) NOT NULL,
                requirement_name VARCHAR(255) DEFAULT NULL,
                label VARCHAR(1000) NOT NULL,
                type VARCHAR(32) NOT NULL,
                cadence VARCHAR(32) NOT NULL,
                quantity DOUBLE PRECISION DEFAULT NULL,
                department_id VARCHAR(64) DEFAULT NULL,
                last_fulfilled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                next_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                held_quantity DOUBLE PRECISION NOT NULL DEFAULT 0,
                active BOOLEAN NOT NULL DEFAULT true,
                origin VARCHAR(16) NOT NULL,
                mode VARCHAR(16) DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_obligation_projection_profile_requirement_item ON compliance_obligation_projection (profile_id, requirement_id, item_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_obligation_projection_profile ON compliance_obligation_projection (profile_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_obligation_projection_next_due ON compliance_obligation_projection (next_due_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_obligation_projection_active ON compliance_obligation_projection (active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_obligation_projection');
    }
}
