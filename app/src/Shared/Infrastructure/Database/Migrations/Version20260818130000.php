<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Интеграция цветов и блеска в покрытие: M2M join `coatings_coating_coating_color`
 * (возможные цвета) + колонки `gloss` (nullable enum) и `is_tintable` (bool) на
 * coatings_coating. Идемпотентно.
 */
final class Version20260818130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coating gains possible colors (M2M), gloss and is_tintable columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS coatings_coating_coating_color (
                coating_id uuid NOT NULL,
                color_id uuid NOT NULL,
                PRIMARY KEY(coating_id, color_id)
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ccc_color_coating ON coatings_coating_coating_color (coating_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ccc_color_color ON coatings_coating_coating_color (color_id)');

        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_ccc_color_coating') THEN
                    ALTER TABLE coatings_coating_coating_color
                        ADD CONSTRAINT fk_ccc_color_coating FOREIGN KEY (coating_id)
                        REFERENCES coatings_coating(id) ON DELETE CASCADE;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_ccc_color_color') THEN
                    ALTER TABLE coatings_coating_coating_color
                        ADD CONSTRAINT fk_ccc_color_color FOREIGN KEY (color_id)
                        REFERENCES coatings_coating_color(id) ON DELETE CASCADE;
                END IF;
            END $$;
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN IF NOT EXISTS gloss varchar(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN IF NOT EXISTS is_tintable boolean NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN IF EXISTS is_tintable');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN IF EXISTS gloss');
        $this->addSql('DROP TABLE IF EXISTS coatings_coating_coating_color');
    }
}
