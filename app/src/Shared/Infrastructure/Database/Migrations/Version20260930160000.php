<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance Д3 (T6): личная карточка `RequirementDocument` (человек × требование) — тонкое состояние
 * документа (статус Formed/Signed + скан). Один документ на требование у человека (уник. индекс).
 * Подписанный заморожен (инвариант в домене). Идемпотентно.
 */
final class Version20260930160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance Д3 T6: compliance_requirement_document (жизненный цикл карточки).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS compliance_requirement_document (
                id UUID NOT NULL,
                profile_compliance_id UUID NOT NULL,
                requirement_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                scan_file_id VARCHAR(64) DEFAULT NULL,
                signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_requirement_document_profile FOREIGN KEY (profile_compliance_id)
                    REFERENCES compliance_profile_compliance (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_requirement_document_profile_requirement ON compliance_requirement_document (profile_compliance_id, requirement_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_requirement_document_requirement ON compliance_requirement_document (requirement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement_document');
    }
}
