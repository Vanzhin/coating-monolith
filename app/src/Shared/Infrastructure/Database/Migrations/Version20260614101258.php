<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Расщепляем колонку recoating_interval JSONB (одна колонка с массивом точек
 * вида {temperature_at, min_minutes, max_minutes}) на две независимые серии
 * времени-при-температуре: min_recoating_interval (NOT NULL) и
 * max_recoating_interval (NULLABLE — null означает «без верхней границы»).
 *
 * После миграции каждая из колонок хранит массив точек
 * {temperature_at, time_in_minutes, is_calculated}, как dry_to_touch/full_cure.
 */
final class Version20260614101258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split recoating_interval into min_recoating_interval + max_recoating_interval (both drying_time_series shape).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN min_recoating_interval JSONB');
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN max_recoating_interval JSONB');

        // min: переписываем каждую точку из recoating_interval в формат drying_time_series.
        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET min_recoating_interval = (
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'temperature_at',  (elem->>'temperature_at')::int,
                        'time_in_minutes', (elem->>'min_minutes')::int,
                        'is_calculated',   false
                    )
                )
                FROM jsonb_array_elements(recoating_interval) elem
            )
        SQL);

        // max: переписываем, только если в ИСХОДНЫХ данных max_minutes задан во всех точках.
        // Если хотя бы в одной точке max_minutes is null — оставляем колонку null целиком.
        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET max_recoating_interval = (
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'temperature_at',  (elem->>'temperature_at')::int,
                        'time_in_minutes', (elem->>'max_minutes')::int,
                        'is_calculated',   false
                    )
                )
                FROM jsonb_array_elements(recoating_interval) elem
            )
            WHERE NOT EXISTS (
                SELECT 1 FROM jsonb_array_elements(recoating_interval) e
                WHERE e->>'max_minutes' IS NULL
            )
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN min_recoating_interval SET NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN recoating_interval');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN recoating_interval JSONB');

        $this->addSql(<<<SQL
            UPDATE coatings_coating
            SET recoating_interval = (
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'temperature_at', (min_elem->>'temperature_at')::int,
                        'min_minutes',    (min_elem->>'time_in_minutes')::int,
                        'max_minutes',    CASE
                            WHEN max_recoating_interval IS NULL THEN NULL
                            ELSE ((max_recoating_interval->((ord - 1)::int)->>'time_in_minutes')::int)
                        END
                    )
                )
                FROM jsonb_array_elements(min_recoating_interval) WITH ORDINALITY AS arr(min_elem, ord)
            )
        SQL);

        $this->addSql('ALTER TABLE coatings_coating ALTER COLUMN recoating_interval SET NOT NULL');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN min_recoating_interval');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN max_recoating_interval');
    }
}
