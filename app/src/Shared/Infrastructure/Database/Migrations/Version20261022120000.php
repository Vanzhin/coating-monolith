<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д5: самостоятельный кросс-персональный агрегат `WriteOffAct` — `compliance_writeoff_act`
 * (заголовок на пару требование×организация) + `compliance_writeoff_line` (строки: профиль+факт+снимок
 * названия+количество+причина). НОВЫЕ таблицы, аддитивно — старые `compliance_write_off_act`/
 * `compliance_write_off_item` (per-person) не трогаем, их перенос — отдельная прод-миграция позже. Идемпотентно.
 */
final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д5: compliance_writeoff_act + compliance_writeoff_line (новый кросс-персон агрегат WriteOffAct).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_writeoff_act (
                id UUID NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                organization_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                commission JSONB DEFAULT NULL,
                act_number VARCHAR(64) DEFAULT NULL,
                act_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                order_number VARCHAR(64) DEFAULT NULL,
                order_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                representative_position VARCHAR(255) DEFAULT NULL,
                representative_fio VARCHAR(255) DEFAULT NULL,
                scan_file_id VARCHAR(64) DEFAULT NULL,
                signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_writeoff_act_organization ON compliance_writeoff_act (organization_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_writeoff_act_requirement ON compliance_writeoff_act (requirement_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_writeoff_act_status ON compliance_writeoff_act (status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_writeoff_line (
                id UUID NOT NULL,
                write_off_act_id UUID NOT NULL,
                profile_id VARCHAR(64) NOT NULL,
                record_id VARCHAR(36) NOT NULL,
                label_snapshot VARCHAR(1000) NOT NULL,
                quantity DOUBLE PRECISION NOT NULL,
                reason VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_writeoff_line_act FOREIGN KEY (write_off_act_id)
                    REFERENCES compliance_writeoff_act (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_writeoff_line_act ON compliance_writeoff_line (write_off_act_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_writeoff_line_record ON compliance_writeoff_line (record_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_writeoff_line');
        $this->addSql('DROP TABLE IF EXISTS compliance_writeoff_act');
    }
}
