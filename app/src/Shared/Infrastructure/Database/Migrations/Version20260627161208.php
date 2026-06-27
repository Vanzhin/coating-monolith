<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627161208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend coatings_coating_search with tags.title (B); add triggers on pivot and coatings_coating_tag.';
    }

    public function up(Schema $schema): void
    {
        // 1. Новая «единая» rebuild-функция, агрегирует title + description + tags.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_rebuild(p_coating_id uuid)
            RETURNS void AS $$
            BEGIN
                INSERT INTO coatings_coating_search (coating_id, search_vector)
                SELECT
                    c.id,
                    setweight(to_tsvector('russian', coalesce(c.title, '')), 'A') ||
                    setweight(to_tsvector('russian', coalesce(c.description, '')), 'A') ||
                    setweight(to_tsvector('russian',
                        coalesce((
                            SELECT string_agg(t.title, ' ')
                            FROM coatings_coating_coating_tag ct
                            JOIN coatings_coating_tag t ON t.id = ct.tag_id
                            WHERE ct.coating_id = c.id
                        ), '')
                    ), 'B')
                FROM coatings_coating c
                WHERE c.id = p_coating_id
                ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector;
            END
            $$ LANGUAGE plpgsql
        SQL);

        // 2. Снять старый upsert и trigger; повесить новый, вызывающий rebuild.
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_upsert_trigger ON coatings_coating');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_upsert()');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_coating()
            RETURNS trigger AS $$
            BEGIN
                PERFORM coatings_coating_search_rebuild(NEW.id);
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_after_coating
            AFTER INSERT OR UPDATE OF title, description
            ON coatings_coating
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_coating()
        SQL);

        // 3. Триггер на pivot-таблицу.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_pivot()
            RETURNS trigger AS $$
            DECLARE
                affected_coating_id uuid;
            BEGIN
                affected_coating_id := COALESCE(NEW.coating_id, OLD.coating_id);
                PERFORM coatings_coating_search_rebuild(affected_coating_id);
                RETURN NULL;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_after_pivot
            AFTER INSERT OR DELETE
            ON coatings_coating_coating_tag
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_pivot()
        SQL);

        // 4. Триггер на coatings_coating_tag (UPDATE title) — пересобрать все связанные coatings.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION coatings_coating_search_trigger_tag()
            RETURNS trigger AS $$
            BEGIN
                PERFORM coatings_coating_search_rebuild(ct.coating_id)
                FROM coatings_coating_coating_tag ct
                WHERE ct.tag_id = NEW.id;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER coatings_coating_search_after_tag
            AFTER UPDATE OF title
            ON coatings_coating_tag
            FOR EACH ROW EXECUTE FUNCTION coatings_coating_search_trigger_tag()
        SQL);

        // 5. Backfill для существующих покрытий.
        $this->addSql(<<<'SQL'
            INSERT INTO coatings_coating_search (coating_id, search_vector)
            SELECT
                c.id,
                setweight(to_tsvector('russian', coalesce(c.title, '')), 'A') ||
                setweight(to_tsvector('russian', coalesce(c.description, '')), 'A') ||
                setweight(to_tsvector('russian',
                    coalesce((
                        SELECT string_agg(t.title, ' ')
                        FROM coatings_coating_coating_tag ct
                        JOIN coatings_coating_tag t ON t.id = ct.tag_id
                        WHERE ct.coating_id = c.id
                    ), '')
                ), 'B')
            FROM coatings_coating c
            ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_after_tag ON coatings_coating_tag');
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_after_pivot ON coatings_coating_coating_tag');
        $this->addSql('DROP TRIGGER IF EXISTS coatings_coating_search_after_coating ON coatings_coating');

        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_trigger_tag()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_trigger_pivot()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_trigger_coating()');
        $this->addSql('DROP FUNCTION IF EXISTS coatings_coating_search_rebuild(uuid)');

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
    }
}
