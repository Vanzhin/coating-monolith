<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FTS integration: chemical resistance substances feed into coating search_vector.';
    }

    public function up(Schema $schema): void
    {
        // 1. SQL mirror of Grade::isSuitable() — single source of truth for "R or LR" in SQL.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION chemical_resistance_is_suitable_grade(g VARCHAR)
            RETURNS BOOLEAN LANGUAGE SQL IMMUTABLE AS $$
                SELECT g = 'R' OR g = 'LR';
            $$
        SQL);

        // 2. Returns space-joined canonical_name + cas + aliases for every substance
        //    with a suitable-grade assessment on this coating. Empty string if none.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION chemical_resistance_suitable_substance_names(cid UUID)
            RETURNS TEXT LANGUAGE SQL STABLE AS $$
                SELECT COALESCE(string_agg(
                    sub.canonical_name
                    || ' ' || COALESCE(sub.cas, '')
                    || ' ' || COALESCE(
                        (SELECT string_agg(value, ' ')
                         FROM jsonb_array_elements_text(sub.aliases) AS value),
                        ''
                    ),
                    ' '
                ), '')
                FROM chemical_resistance_assessment a
                JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
                WHERE a.coating_id = cid
                  AND chemical_resistance_is_suitable_grade(a.grade)
            $$
        SQL);

        // 3. REDEFINE coatings_coating_search_rebuild to include segment D.
        //    Body is copied verbatim from Version20260627161208 up(), then segment D is appended.
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
                    ), 'B') ||
                    setweight(to_tsvector('russian',
                        coalesce(chemical_resistance_suitable_substance_names(p_coating_id), '')
                    ), 'D')
                FROM coatings_coating c
                WHERE c.id = p_coating_id
                ON CONFLICT (coating_id) DO UPDATE SET search_vector = EXCLUDED.search_vector;
            END
            $$ LANGUAGE plpgsql
        SQL);

        // 4a. Trigger function: assessment INSERT/UPDATE/DELETE → rebuild search for coating(s).
        //     Respects session-scoped suppression flag for batch-mode seeding (Task 22).
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION _cr_recalc_search_on_assessment_row()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('chemical_resistance.suppress_search_recalc', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF (TG_OP = 'UPDATE' AND NEW.coating_id <> OLD.coating_id) THEN
                    PERFORM coatings_coating_search_rebuild(OLD.coating_id);
                END IF;
                PERFORM coatings_coating_search_rebuild(COALESCE(NEW.coating_id, OLD.coating_id));
                RETURN COALESCE(NEW, OLD);
            END $$
        SQL);

        // 4b. Trigger function: substance canonical_name/aliases/cas UPDATE → rebuild
        //     search for every coating that has an assessment for that substance.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION _cr_recalc_search_on_substance_change()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF current_setting('chemical_resistance.suppress_search_recalc', true) = 'on' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                PERFORM coatings_coating_search_rebuild(a.coating_id)
                FROM chemical_resistance_assessment a
                WHERE a.substance_id = COALESCE(NEW.id, OLD.id);
                RETURN COALESCE(NEW, OLD);
            END $$
        SQL);

        // 5. Triggers.
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_recalc_search_on_assessment
            AFTER INSERT OR UPDATE OR DELETE ON chemical_resistance_assessment
            FOR EACH ROW EXECUTE FUNCTION _cr_recalc_search_on_assessment_row()
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_recalc_search_on_substance_update
            AFTER UPDATE OF canonical_name, aliases, cas ON chemical_resistance_substance
            FOR EACH ROW EXECUTE FUNCTION _cr_recalc_search_on_substance_change()
        SQL);

        // 6. Backfill: rebuild search_vector for every existing coating with the new function.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                rec RECORD;
            BEGIN
                FOR rec IN SELECT id FROM coatings_coating LOOP
                    PERFORM coatings_coating_search_rebuild(rec.id);
                END LOOP;
            END $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop triggers first, then trigger functions, then helper functions.
        $this->addSql('DROP TRIGGER IF EXISTS trg_recalc_search_on_substance_update ON chemical_resistance_substance');
        $this->addSql('DROP TRIGGER IF EXISTS trg_recalc_search_on_assessment ON chemical_resistance_assessment');
        $this->addSql('DROP FUNCTION IF EXISTS _cr_recalc_search_on_substance_change()');
        $this->addSql('DROP FUNCTION IF EXISTS _cr_recalc_search_on_assessment_row()');
        $this->addSql('DROP FUNCTION IF EXISTS chemical_resistance_suitable_substance_names(UUID)');
        $this->addSql('DROP FUNCTION IF EXISTS chemical_resistance_is_suitable_grade(VARCHAR)');

        // Revert coatings_coating_search_rebuild to pre-Task-21 body (verbatim from Version20260627161208 up()).
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
    }
}
