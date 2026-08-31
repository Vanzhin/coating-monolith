<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Цвет слоя системы становится обязательным: color_id → NOT NULL.
 * Предусловие — бэкфил цветов уже прогнан (app:coating-system:fill-layer-colors, Деплой 1):
 * миграция падает с внятным сообщением, если остались слои без цвета.
 * FK fk_csl_color пересоздаётся без ON DELETE SET NULL (несовместимо с NOT NULL) — теперь
 * удаление цвета, на который ссылается слой, запрещено (NO ACTION). Идемпотентно.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CoatingSystem layer: color_id NOT NULL + FK without ON DELETE SET NULL (color now mandatory).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM coating_system_layer WHERE color_id IS NULL) THEN
                    RAISE EXCEPTION 'coating_system_layer: есть слои без color_id — сначала прогони app:coating-system:fill-layer-colors (Деплой 1).';
                END IF;
            END $$;
        SQL);
        $this->addSql('ALTER TABLE coating_system_layer DROP CONSTRAINT IF EXISTS fk_csl_color');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_csl_color') THEN
                    ALTER TABLE coating_system_layer
                        ADD CONSTRAINT fk_csl_color FOREIGN KEY (color_id)
                        REFERENCES coatings_coating_color(id);
                END IF;
            END $$;
        SQL);
        $this->addSql('ALTER TABLE coating_system_layer ALTER COLUMN color_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coating_system_layer ALTER COLUMN color_id DROP NOT NULL');
        $this->addSql('ALTER TABLE coating_system_layer DROP CONSTRAINT IF EXISTS fk_csl_color');
        $this->addSql(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_csl_color') THEN
                    ALTER TABLE coating_system_layer
                        ADD CONSTRAINT fk_csl_color FOREIGN KEY (color_id)
                        REFERENCES coatings_coating_color(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);
    }
}
