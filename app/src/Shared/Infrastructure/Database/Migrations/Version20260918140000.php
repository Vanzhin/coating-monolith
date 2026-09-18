<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Справочник проектов (context Reports): проект принадлежит одному контрагенту-заказчику
 * (many-to-one). Идемпотентно: CREATE IF NOT EXISTS + FK через guard по pg_constraint.
 */
final class Version20260918140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reports_project directory table with FK to reports_counterparty.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reports_project (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(100) NOT NULL,
                description VARCHAR(750) DEFAULT NULL,
                counterparty_id VARCHAR(36) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_project_title ON reports_project (title)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reports_project_counterparty ON reports_project (counterparty_id)');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_reports_project_counterparty') THEN
                    ALTER TABLE reports_project
                        ADD CONSTRAINT fk_reports_project_counterparty
                        FOREIGN KEY (counterparty_id) REFERENCES reports_counterparty (id);
                END IF;
            END $$;
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS reports_project');
    }
}
