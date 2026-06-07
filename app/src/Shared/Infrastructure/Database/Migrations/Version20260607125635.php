<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Добавляет поле coating_base (ISO 12944-5: AK/AY/ESI/EP/PYR/FEVE/PAS/PS).
 * Существующие покрытия заполняем дефолтом EP (эпоксидное) — самый частый тип.
 */
final class Version20260607125635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coating_base (ISO 12944-5 enum) column to coatings_coating.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN coating_base VARCHAR(8)');
        $this->addSql("UPDATE coatings_coating SET coating_base = 'EP' WHERE coating_base IS NULL");
        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN coating_base SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN coating_base');
    }
}
