<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Rekey-деплой, схема: колонка item_id на проекции обязанностей (значение проставит data-миграция/ребилдер). */
final class Version20261020120800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_tracked_obligation.item_id (rekey label→itemId, схема)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS item_id VARCHAR(64)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation DROP COLUMN IF EXISTS item_id');
    }
}
