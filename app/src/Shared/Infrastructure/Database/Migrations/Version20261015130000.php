<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: у требования появляется вид журнала (journal_kind) — для не материального задаёт схему полей
 * инструктажа. Nullable: у материального/невыбранного пусто. Существующие требования не затронуты.
 */
final class Version20261015130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_requirement.journal_kind (nullable) — вид журнала инструктажа.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement ADD COLUMN IF NOT EXISTS journal_kind VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN IF EXISTS journal_kind');
    }
}
