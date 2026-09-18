<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Справочник контрагентов (context Reports) — организации, участвующие в проектах/отчётах.
 * Идемпотентно: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20260918130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reports_counterparty directory table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS reports_counterparty (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(100) NOT NULL,
                description VARCHAR(750) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_counterparty_title ON reports_counterparty (title)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS reports_counterparty');
    }
}
