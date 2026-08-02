<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Финальный вид схемы кэша поиска систем: min/max/tsvector — в отдельной таблице
 * coating_system_search (1:1). Compliance — в существующей coating_system_compliance (1:N).
 * Никаких кэш-полей на coating_system.
 *
 * Идемпотентно: работает независимо от того, какие из промежуточных миграций
 * (170000, 180000) уже накатаны в среде.
 */
final class Version20260801190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'coating_system: drop cache columns; coating_system_search: restore min/max/tsvector layout.';
    }

    public function up(Schema $schema): void
    {
        // Убрать лишнее с coating_system
        $this->addSql('DROP INDEX IF EXISTS idx_cs_min_building');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_max_app_temp');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system
              DROP COLUMN IF EXISTS min_building_time_at_20_minutes,
              DROP COLUMN IF EXISTS max_layer_application_min_temp
        SQL);
        $this->addSql('DROP INDEX IF EXISTS idx_cs_compliance_matches');
        $this->addSql('ALTER TABLE coating_system DROP COLUMN IF EXISTS compliance_matches');

        // Восстановить min/max в coating_system_search
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              ADD COLUMN IF NOT EXISTS min_building_time_at_20_minutes INT,
              ADD COLUMN IF NOT EXISTS max_layer_application_min_temp  INT
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_min_building ON coating_system_search (min_building_time_at_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_max_app_temp ON coating_system_search (max_layer_application_min_temp)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_css_max_app_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_css_min_building');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              DROP COLUMN IF EXISTS max_layer_application_min_temp,
              DROP COLUMN IF EXISTS min_building_time_at_20_minutes
        SQL);
    }
}
