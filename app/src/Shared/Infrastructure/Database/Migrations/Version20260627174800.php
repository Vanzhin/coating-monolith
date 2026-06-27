<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Отдельная FTS-таблица для тегов: coatings_coating_tag_search — зеркаль архитектуры
 * coatings_coating_search. Нужна, чтобы tag-autocomplete (Tagify) не вычислял
 * to_tsvector(title) в каждом запросе и использовал GIN-индекс.
 *
 * Параллельно — GIN-trgm индекс на coatings_coating_tag.title, чтобы fuzzy-fallback
 * через WORD_SIMILARITY был на индексе, а не на seq scan.
 *
 * Триггер на coatings_coating_tag (INSERT/UPDATE OF title) пересобирает строку,
 * на DELETE — снимается ON DELETE CASCADE по FK.
 */
final class Version20260627174800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coatings_coating_tag_search (tsvector + GIN) with trigger; trgm index on tag.title for fuzzy.';
    }

    public function up(Schema $schema): void
    {
        // pg_trgm уже включён ранней миграцией; на всякий случай ещё раз idempotent.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS coatings_coating_tag_search (
                tag_id varchar(36) PRIMARY KEY,
                search_vector tsvector NOT NULL,
                CONSTRAINT fk_coating_tag_search_tag
                    FOREIGN KEY (tag_id) REFERENCES coatings_coating_tag(id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS coatings_coating_tag_search_vector_idx
                ON coatings_coating_tag_search USING GIN (search_vector)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS coatings_coating_tag_title_trgm_idx
                ON coatings_coating_tag USING GIN (title gin_trgm_ops)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_tag_search_rebuild(p_tag_id varchar)
            RETURNS void AS $$
            BEGIN
                INSERT INTO coatings_coating_tag_search (tag_id, search_vector)
                SELECT t.id, to_tsvector('russian', coalesce(t.title, ''))
                FROM coatings_coating_tag t
                WHERE t.id = p_tag_id
                ON CONFLICT (tag_id) DO UPDATE SET search_vector = EXCLUDED.search_vector;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_tag_search_trigger()
            RETURNS trigger AS $$
            BEGIN
                PERFORM coatings_coating_tag_search_rebuild(NEW.id);
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_tag_search_after_tag ON coatings_coating_tag');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_tag_search_after_tag
            AFTER INSERT OR UPDATE OF title
            ON coatings_coating_tag
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_tag_search_trigger()
        SQL);

        // Backfill для существующих тегов.
        $this->addSql(<<<'SQL'
            INSERT INTO coatings_coating_tag_search (tag_id, search_vector)
            SELECT t.id, to_tsvector('russian', coalesce(t.title, ''))
            FROM coatings_coating_tag t
            ON CONFLICT (tag_id) DO UPDATE SET search_vector = EXCLUDED.search_vector
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_tag_search_after_tag ON coatings_coating_tag');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_tag_search_trigger()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_tag_search_rebuild(varchar)');
        $this->addSql('DROP INDEX IF EXISTS coatings_coating_tag_title_trgm_idx');
        $this->addSql('DROP TABLE IF EXISTS coatings_coating_tag_search');
        // pg_trgm extension оставляем — может использоваться другими миграциями.
    }
}
