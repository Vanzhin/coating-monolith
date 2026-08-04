<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename min_building_time_at_20_minutes → min_application_time_at_20_minutes
 * (терминология после дизайн-ревью: это про мин.время нанесения, не сборки).
 * Идемпотентно: не падает при повторном прогоне.
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coating_system_search: rename min_building_time_at_20_minutes to min_application_time_at_20_minutes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'coating_system_search'
                      AND column_name = 'min_building_time_at_20_minutes'
                ) THEN
                    ALTER TABLE coating_system_search
                        RENAME COLUMN min_building_time_at_20_minutes
                                 TO min_application_time_at_20_minutes;
                END IF;
            END $$
        SQL);

        $this->addSql('DROP INDEX IF EXISTS idx_css_min_building');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_min_app_time ON coating_system_search (min_application_time_at_20_minutes)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_css_min_app_time');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'coating_system_search'
                      AND column_name = 'min_application_time_at_20_minutes'
                ) THEN
                    ALTER TABLE coating_system_search
                        RENAME COLUMN min_application_time_at_20_minutes
                                 TO min_building_time_at_20_minutes;
                END IF;
            END $$
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_min_building ON coating_system_search (min_building_time_at_20_minutes)');
    }
}
