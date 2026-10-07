<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: у требования появляется свой файл-шаблон документа (template_file_id — uuid в едином файловом
 * реестре). Nullable: пусто → печатается дефолтная карточка. Материальные/существующие требования не затронуты.
 */
final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_requirement.template_file_id (nullable) — свой шаблон документа у требования.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement ADD COLUMN IF NOT EXISTS template_file_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement DROP COLUMN IF EXISTS template_file_id');
    }
}
