<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Учёт по человеку (context Compliance, Д3): `ProfileCompliance` (корень, один на профиль) + событийная
 * проекция `TrackedObligation` (даты хранимые, статус производный, `active`=документ подписан) +
 * журнал фактов `FulfillmentRecord`. Индексы под дашборд/алерт Д4. Идемпотентно.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д3: profile_compliance + tracked_obligation (projection) + fulfillment_record.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_profile_compliance (
                id UUID NOT NULL,
                profile_id VARCHAR(64) NOT NULL,
                excluded_keys JSONB NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_compliance_profile_compliance_profile ON compliance_profile_compliance (profile_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_tracked_obligation (
                id UUID NOT NULL,
                profile_compliance_id UUID NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                requirement_name VARCHAR(255) NOT NULL,
                label VARCHAR(255) NOT NULL,
                type VARCHAR(32) NOT NULL,
                cadence JSONB NOT NULL,
                quantity JSONB DEFAULT NULL,
                department_id VARCHAR(64) NOT NULL,
                last_fulfilled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                next_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                active BOOLEAN NOT NULL DEFAULT FALSE,
                origin VARCHAR(16) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_tracked_obligation_profile FOREIGN KEY (profile_compliance_id)
                    REFERENCES compliance_profile_compliance (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_profile ON compliance_tracked_obligation (profile_compliance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_requirement ON compliance_tracked_obligation (requirement_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_next_due ON compliance_tracked_obligation (next_due_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_type ON compliance_tracked_obligation (type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_department ON compliance_tracked_obligation (department_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tracked_obligation_active ON compliance_tracked_obligation (active)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_fulfillment_record (
                id UUID NOT NULL,
                profile_compliance_id UUID NOT NULL,
                obligation_key VARCHAR(320) NOT NULL,
                fulfilled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                quantity JSONB DEFAULT NULL,
                wear_percent DOUBLE PRECISION DEFAULT NULL,
                note TEXT DEFAULT NULL,
                manual_due_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                returned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                returned_quantity JSONB DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_fulfillment_record_profile FOREIGN KEY (profile_compliance_id)
                    REFERENCES compliance_profile_compliance (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_fulfillment_record_profile ON compliance_fulfillment_record (profile_compliance_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_fulfillment_record_key ON compliance_fulfillment_record (obligation_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_fulfillment_record');
        $this->addSql('DROP TABLE IF EXISTS compliance_tracked_obligation');
        $this->addSql('DROP TABLE IF EXISTS compliance_profile_compliance');
    }
}
