<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Read-model поиска систем — отдельная таблица `coating_system_search` (одна строка на систему).
 * Ранее search-поля были добавлены в compliance-таблицу, что неправильно: у системы без
 * ISO/NORSOK-совпадений в compliance строк нет → она бы не находилась FTS. Плюс дублирование
 * одного tsvector на каждую compliance-строку.
 *
 * Сейчас compliance-таблица возвращается к своему единственному смыслу: (system_id, standard,
 * category, durability). Search-поля живут в отдельной таблице, обновляемой тем же проектором.
 */
final class Version20260801160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split search-fields out of coating_system_compliance into a dedicated coating_system_search table (one row per system).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS coating_system_search (
                system_id                 UUID     PRIMARY KEY,
                sum_min_recoat_20_minutes INT,
                max_application_min_temp  INT,
                search_tsvector           TSVECTOR,
                FOREIGN KEY (system_id) REFERENCES coating_system(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_search_tsv ON coating_system_search USING GIN (search_tsvector)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_sum_recoat ON coating_system_search (sum_min_recoat_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_css_max_temp   ON coating_system_search (max_application_min_temp)');

        $this->addSql('DROP INDEX IF EXISTS idx_csc_max_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_csc_sum_recoat');
        $this->addSql('DROP INDEX IF EXISTS idx_csc_search_tsv');
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_compliance
              DROP COLUMN IF EXISTS search_tsvector,
              DROP COLUMN IF EXISTS max_application_min_temp,
              DROP COLUMN IF EXISTS sum_min_recoat_20_minutes
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE coating_system_compliance
              ADD COLUMN IF NOT EXISTS sum_min_recoat_20_minutes INT,
              ADD COLUMN IF NOT EXISTS max_application_min_temp  INT,
              ADD COLUMN IF NOT EXISTS search_tsvector           TSVECTOR
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csc_search_tsv ON coating_system_compliance USING GIN (search_tsvector)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csc_sum_recoat ON coating_system_compliance (sum_min_recoat_20_minutes)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_csc_max_temp   ON coating_system_compliance (max_application_min_temp)');

        $this->addSql('DROP INDEX IF EXISTS idx_css_max_temp');
        $this->addSql('DROP INDEX IF EXISTS idx_css_sum_recoat');
        $this->addSql('DROP INDEX IF EXISTS idx_css_search_tsv');
        $this->addSql('DROP TABLE IF EXISTS coating_system_search');
    }
}
