<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: compliance_tracked_obligation.mode (nullable) — режим материальной позиции на проекции
 * (инвентарная/расходная). Null у нематериальных. Проекция пересобирается, бэкофилл не нужен — mode встанет
 * при следующей пересборке (app:compliance:rebuild-projection).
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: compliance_tracked_obligation.mode (nullable) — режим позиции на проекции.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation ADD COLUMN IF NOT EXISTS mode VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_tracked_obligation DROP COLUMN IF EXISTS mode');
    }
}
