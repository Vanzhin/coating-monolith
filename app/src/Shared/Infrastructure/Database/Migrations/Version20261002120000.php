<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д5: акт списания СИЗ — таблица `compliance_write_off_act` (на требование, черновик→оформлен,
 * строки-корзина в jsonb со ссылкой на факт/акт получения + причина на строку, комиссия, скан). Факту выдачи
 * возвращаем `document_id` (акт получения — провенанс для трассировки «из какого в какой акт»). Идемпотентно.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д5: compliance_write_off_act + fulfillment_record.document_id (провенанс списания).';
    }

    public function up(Schema $schema): void
    {
        // Факт выдачи = «item», гуляющий из акта в акт: document_id (откуда выдан) + write_off_act_id (куда списан) + причина.
        $this->addSql('ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS document_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS write_off_act_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS write_off_reason VARCHAR(32) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_write_off_act (
                id UUID NOT NULL,
                profile_compliance_id UUID NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                commission JSONB DEFAULT NULL,
                act_number VARCHAR(64) DEFAULT NULL,
                act_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                scan_file_id VARCHAR(64) DEFAULT NULL,
                signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_write_off_act_profile FOREIGN KEY (profile_compliance_id)
                    REFERENCES compliance_profile_compliance (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_write_off_act_requirement ON compliance_write_off_act (requirement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_write_off_act');
        $this->addSql('ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS document_id');
        $this->addSql('ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS write_off_act_id');
        $this->addSql('ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS write_off_reason');
    }
}
