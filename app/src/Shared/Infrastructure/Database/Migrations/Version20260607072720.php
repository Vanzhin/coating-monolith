<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Полнотекстовый поиск по покрытиям, search-вектор вынесен в отдельную таблицу coatings_coating_search.
 *
 * - coatings_coating_search.search_vector (tsvector) — title (A) + description (A), словарь russian.
 * - GIN-индекс на search_vector для быстрого FTS.
 * - Триггер на coatings_coating BEFORE INSERT/UPDATE OF (title, description):
 *   делает UPSERT в search-таблицу.
 * - pg_trgm + GIN-trgm индекс на coatings_coating.title — fuzzy-fallback при опечатках.
 */
final class Version20260607072720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coatings_coating_search (tsvector + GIN) with upsert-trigger and pg_trgm index on title.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql(<<<'SQL'
            CREATE TABLE coatings_coating_search (
                coating_id uuid PRIMARY KEY REFERENCES coatings_coating(id) ON DELETE CASCADE,
                search_vector tsvector NOT NULL
            )
        SQL);

        $this->addSql(
            'CREATE INDEX coatings_coating_search_vector_idx ON coatings_coating_search USING gin (search_vector)'
        );

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_upsert()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO coatings_coating_search (coating_id, search_vector)
                VALUES (
                    NEW.id,
                    setweight(to_tsvector('russian', coalesce(NEW.title, '')), 'A') ||
                    setweight(to_tsvector('russian', coalesce(NEW.description, '')), 'A')
                )
                ON CONFLICT (coating_id) DO UPDATE
                SET search_vector = EXCLUDED.search_vector;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_upsert_trigger
            AFTER INSERT OR UPDATE OF title, description
            ON coatings_coating
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_upsert()
        SQL);

        // Backfill для уже существующих покрытий
        $this->addSql(<<<'SQL'
            INSERT INTO coatings_coating_search (coating_id, search_vector)
            SELECT
                id,
                setweight(to_tsvector('russian', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('russian', coalesce(description, '')), 'A')
            FROM coatings_coating
        SQL);

        $this->addSql('CREATE INDEX coatings_coating_title_trgm_idx ON coatings_coating USING gin (title gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS coatings_coating_title_trgm_idx');
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_upsert_trigger ON coatings_coating');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_upsert()');
        $this->addSql('DROP TABLE IF EXISTS coatings_coating_search');
        // pg_trgm extension оставляем
    }
}
