<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Добавляем coatings_coating.drying_max_temp (верхняя граница рабочего
 * температурного диапазона). Доменный инвариант:
 *   application_min_temp < drying_max_temp
 *   и все TimeAtTemperature точки в [application_min_temp, drying_max_temp].
 *
 * Backfill для existing rows вычисляем как:
 *   GREATEST(50, application_min_temp + 1, MAX-температуры-всех-точек)
 * чтобы существующие данные автоматически удовлетворяли инвариант.
 *
 * Точки хранятся в jsonb-колонках:
 *   dry_to_touch, full_cure         — массив объектов {temperature_at, ...}
 *   min/max_recoating_interval      — рекурсивное дерево
 *     {default: {points: [...]}, children: {<key>: <node>, ...}}
 *
 * jsonb_path_query(value, '$.**.temperature_at') обходит все вложения
 * на любой глубине — работает в PG 12+.
 */
final class Version20260629145151 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coatings_coating.drying_max_temp with backfill respecting temperature invariant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN drying_max_temp INTEGER NOT NULL DEFAULT 50');

        // Backfill: drying_max_temp = max из (50, app_min+1, любая temp_at в точках).
        // COALESCE на NULL результат jsonb_path_query (если в точках нечего достать).
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating c SET drying_max_temp = GREATEST(
                50,
                c.application_min_temp + 1,
                COALESCE((
                    SELECT MAX((p)::text::int) FROM jsonb_path_query(
                        jsonb_build_array(c.dry_to_touch, c.full_cure, c.min_recoating_interval, c.max_recoating_interval),
                        '$.**.temperature_at'
                    ) AS p
                ), 0)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN drying_max_temp');
    }
}
