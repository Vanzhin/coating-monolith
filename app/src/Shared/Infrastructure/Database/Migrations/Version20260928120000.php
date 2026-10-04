<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Справочник должностей (context Personnel) — зеркало reports_counterparty без ИНН.
 * Идемпотентно: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create personnel_position directory table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS personnel_position (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(150) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_personnel_position_title ON personnel_position (title)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS personnel_position');
    }
}
