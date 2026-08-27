<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Снапшот максимальной температуры эксплуатации системы (слабое звено — min по слоям) в поисковый кэш.
 * Четыре столбца: сухое тепло / погружение × непрерывная (continuous) / пиковая (peak).
 * Сухое тепло всегда есть (у покрытия дефолт по основе), погружение — NULL, если хоть у одного слоя
 * нет immersion-пределов. Заполняются проектором (RefreshCacheOn*MutatedHandler) и rebuild-командой.
 * Бэкфилла данных покрытий тут нет — только столбцы производного кэша.
 */
final class Version20260827120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CoatingSystem search: dry_heat/immersion × continuous/peak max operating temperature snapshot columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              ADD COLUMN IF NOT EXISTS dry_heat_continuous_max  INT,
              ADD COLUMN IF NOT EXISTS dry_heat_peak_max         INT,
              ADD COLUMN IF NOT EXISTS immersion_continuous_max  INT,
              ADD COLUMN IF NOT EXISTS immersion_peak_max        INT
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_dry_cont ON coating_system_search (dry_heat_continuous_max)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_dry_peak ON coating_system_search (dry_heat_peak_max)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_imm_cont ON coating_system_search (immersion_continuous_max)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_imm_peak ON coating_system_search (immersion_peak_max)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_css_dry_cont');
        $this->addSql('DROP INDEX IF EXISTS idx_css_dry_peak');
        $this->addSql('DROP INDEX IF EXISTS idx_css_imm_cont');
        $this->addSql('DROP INDEX IF EXISTS idx_css_imm_peak');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_search
              DROP COLUMN IF EXISTS dry_heat_continuous_max,
              DROP COLUMN IF EXISTS dry_heat_peak_max,
              DROP COLUMN IF EXISTS immersion_continuous_max,
              DROP COLUMN IF EXISTS immersion_peak_max
        SQL);
    }
}
