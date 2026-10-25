<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Триграммный GIN-индекс на chemical_resistance_substance.canonical_name — ускоряет
 * fuzzy fallback (WORD_SIMILARITY) в SubstanceRepository::findByFilter, когда точный
 * ILIKE ничего не нашёл (опечатки типа «этонол» → «Этанол»). Сам WORD_SIMILARITY
 * работает и без индекса (pg_trgm уже используется для Coatings), индекс — только
 * про скорость на больших справочниках.
 */
final class Version20261120000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'chemical_resistance_substance: GIN trgm-индекс на canonical_name для fuzzy-поиска';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_substance_canonical_trgm ON chemical_resistance_substance USING GIN (canonical_name gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ix_substance_canonical_trgm');
    }
}
