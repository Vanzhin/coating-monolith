<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д5: акт списания СИЗ порциями — `compliance_write_off_act` (заголовок-документ, на требование,
 * черновик→оформлен) + `compliance_write_off_item` (порции: сколько конкретного факта-выдачи списано и
 * почему). Факту выдачи возвращаем `document_id` (акт получения — провенанс «откуда выдано»). Идемпотентно.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д5: compliance_write_off_act + compliance_write_off_item (порции) + fulfillment_record.document_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_fulfillment_record ADD COLUMN IF NOT EXISTS document_id VARCHAR(36) DEFAULT NULL');
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
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_write_off_item (
                id UUID NOT NULL,
                write_off_act_id UUID NOT NULL,
                record_id VARCHAR(36) NOT NULL,
                quantity DOUBLE PRECISION NOT NULL,
                reason VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_write_off_item_act FOREIGN KEY (write_off_act_id)
                    REFERENCES compliance_write_off_act (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_write_off_item_act ON compliance_write_off_item (write_off_act_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_write_off_item_record ON compliance_write_off_item (record_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_write_off_item');
        $this->addSql('DROP TABLE IF EXISTS compliance_write_off_act');
        $this->addSql('ALTER TABLE compliance_fulfillment_record DROP COLUMN IF EXISTS document_id');
    }
}
