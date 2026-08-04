<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Производные бизнес-величины CoatingSystem переезжают на саму сущность:
 *  - min_building_time_at_20_minutes — суммарное мин.время сборки при 20 °C (интерполированное);
 *  - max_layer_application_min_temp — max мин.T нанесения по слоям.
 *
 * Пересчитываются в domain (postMutate) и хранятся на coating_system.
 * Из вспомогательной search-таблицы эти поля удаляются (они больше не проекция).
 */
final class Version20260801170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CoatingSystem: min_building_time_at_20_minutes + max_layer_application_min_temp as own columns; drop them from coating_system_search.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system
              ADD COLUMN IF NOT EXISTS min_building_time_at_20_minutes INT,
              ADD COLUMN IF NOT EXISTS max_layer_application_min_temp  INT
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cs_min_building ON coating_system (min_building_time_at_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cs_max_app_temp ON coating_system (max_layer_application_min_temp)');

        $this->addSql('DROP INDEX IF EXISTS idx_css_max_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_css_sum_recoat');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              DROP COLUMN IF EXISTS sum_min_recoat_20_minutes,
              DROP COLUMN IF EXISTS max_application_min_temp
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              ADD COLUMN IF NOT EXISTS sum_min_recoat_20_minutes INT,
              ADD COLUMN IF NOT EXISTS max_application_min_temp  INT
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_sum_recoat ON coating_system_search (sum_min_recoat_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_max_temp   ON coating_system_search (max_application_min_temp)');

        $this->addSql('DROP INDEX IF EXISTS idx_cs_max_app_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_min_building');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system
              DROP COLUMN IF EXISTS max_layer_application_min_temp,
              DROP COLUMN IF EXISTS min_building_time_at_20_minutes
        SQL);
    }
}
