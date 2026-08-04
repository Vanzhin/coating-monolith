<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Плана 1 (coating-system-search): наполнение read-модели поиска систем и подготовка домена.
 *  1) coating_system_tag — many-to-many CoatingSystem↔Tag (переиспользуется существующий coating_tag).
 *  2) coating_system_compliance — поля read-модели поиска: sum_min_recoat_20_minutes,
 *     max_application_min_temp, search_tsvector + индексы (GIN на tsvector, btree на остальные).
 *  3) coating.recoating_interpolation_model — модель пересчёта мин.интервала перекрытия
 *     под фактическую толщину слоя (LINEAR по умолчанию).
 *
 * Идемпотентно: IF NOT EXISTS везде где применимо.
 */
final class Version20260801150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coating-system search: coating_system_tag + read-model columns on coating_system_compliance + recoating_interpolation_model on coating.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS coating_system_tag (
                coating_system_id UUID         NOT NULL,
                tag_id            VARCHAR(36)  NOT NULL,
                PRIMARY KEY (coating_system_id, tag_id),
                FOREIGN KEY (coating_system_id) REFERENCES coating_system(id)        ON DELETE CASCADE,
                FOREIGN KEY (tag_id)            REFERENCES coatings_coating_tag(id)  ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cst_tag ON coating_system_tag (tag_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_compliance
              ADD COLUMN IF NOT EXISTS sum_min_recoat_20_minutes INT,
              ADD COLUMN IF NOT EXISTS max_application_min_temp  INT,
              ADD COLUMN IF NOT EXISTS search_tsvector           TSVECTOR
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csc_search_tsv ON coating_system_compliance USING GIN (search_tsvector)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csc_sum_recoat ON coating_system_compliance (sum_min_recoat_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csc_max_temp   ON coating_system_compliance (max_application_min_temp)');

        $this->addSql(<<<'SQL'
            ALTER TABLE coatings_coating
              ADD COLUMN IF NOT EXISTS recoating_interpolation_model VARCHAR(32) NOT NULL DEFAULT 'LINEAR'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN IF EXISTS recoating_interpolation_model');

        $this->addSql('DROP INDEX IF EXISTS idx_csc_max_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_csc_sum_recoat');
        $this->addSql('DROP INDEX IF EXISTS idx_csc_search_tsv');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_compliance
              DROP COLUMN IF EXISTS search_tsvector,
              DROP COLUMN IF EXISTS max_application_min_temp,
              DROP COLUMN IF EXISTS sum_min_recoat_20_minutes
        SQL);

        $this->addSql('DROP INDEX IF EXISTS idx_cst_tag');
        $this->addSql('DROP TABLE IF EXISTS coating_system_tag');
    }
}
