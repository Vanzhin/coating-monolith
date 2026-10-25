<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Триграммный GIN-индекс на chemical_resistance_substance.canonical_name — ускоряет
 * фазу 1 поиска в SubstanceRepository::findByFilter, инфиксный `canonical_name ILIKE
 * '%…%'`: только этот оператор (и `%`/`<%`) способен использовать GIN gin_trgm_ops.
 * Фазу 2 — fuzzy fallback через функцию WORD_SIMILARITY(...) — индекс НЕ ускоряет:
 * вызов функции не индекс-eligible, Postgres всегда идёт полным сканом; сам
 * WORD_SIMILARITY работает и без индекса (pg_trgm уже используется для Coatings).
 */
final class Version20261120000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'chemical_resistance_substance: GIN trgm-индекс на canonical_name для ILIKE-поиска';
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
