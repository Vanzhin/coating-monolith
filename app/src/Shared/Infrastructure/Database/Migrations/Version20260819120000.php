<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Слой системы покрытий получает выбранный цвет: колонка color_id (nullable ради
 * легаси-слоёв) + FK на справочник цветов. Обязательность цвета форсится на записи,
 * не в БД. Идемпотентно.
 */
final class Version20260819120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coating system layer gains selected color (color_id, nullable) with FK to color catalog.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating_system_layer ADD COLUMN IF NOT EXISTS color_id uuid DEFAULT NULL');

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_csl_color') THEN
                    ALTER TABLE coating_system_layer
                        ADD CONSTRAINT fk_csl_color FOREIGN KEY (color_id)
                        REFERENCES coatings_coating_color(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csl_color ON coating_system_layer (color_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating_system_layer DROP COLUMN IF EXISTS color_id');
    }
}
