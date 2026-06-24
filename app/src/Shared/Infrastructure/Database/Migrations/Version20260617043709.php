<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Оборачивает существующие JSONB-значения колонок min_recoating_interval / max_recoating_interval
 * в форму дерева {default, children}, требуемую новым типом recoating_interval_tree.
 */
final class Version20260617043709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wrap coatings_coating recoating interval columns into RecoatingIntervalTree shape.';
    }

    public function up(Schema $schema): void
    {
        // Идемпотентно: WHERE jsonb_typeof = 'array' пропускает уже обёрнутые object-строки,
        // так что повторный прогон миграции (на partial-deployed окружении) не двойне-обернёт.
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET min_recoating_interval = jsonb_build_object(
              'default',  min_recoating_interval,
              'children', '{}'::jsonb
            )
            WHERE jsonb_typeof(min_recoating_interval) = 'array'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET max_recoating_interval = jsonb_build_object(
              'default',  max_recoating_interval,
              'children', '{}'::jsonb
            )
            WHERE max_recoating_interval IS NOT NULL
              AND jsonb_typeof(max_recoating_interval) = 'array'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Тоже идемпотентно: разворачиваем только object-строки.
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET min_recoating_interval = min_recoating_interval->'default'
            WHERE jsonb_typeof(min_recoating_interval) = 'object'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE coatings_coating
            SET max_recoating_interval = max_recoating_interval->'default'
            WHERE max_recoating_interval IS NOT NULL
              AND jsonb_typeof(max_recoating_interval) = 'object'
        SQL);
    }
}
