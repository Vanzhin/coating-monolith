<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ссылки-снимки отчёта из плоских пар *_id/*_title → в JSON-колонку на ссылку (VO Reference {id,title}).
 * Реформирует схему, добавленную Version20260918160000 (фича не задеплоена, данных нет). id остаётся
 * доступным для аналитики через выражение system->>'id' (экспрешн-индексы). Идемпотентно.
 */
final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report references: flat *_id/*_title columns → JSON Reference columns.';
    }

    public function up(Schema $schema): void
    {
        // 1) Новые JSON-колонки.
        foreach (['project', 'customer', 'contractor', 'system'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report ADD COLUMN IF NOT EXISTS %s JSON DEFAULT NULL', $column));
        }
        // 2) Перенос существующих снимков {id,title} из плоских пар (если ещё есть).
        foreach (['project', 'customer', 'contractor', 'system'] as $ref) {
            $this->addSql(sprintf(
                "UPDATE report SET %1\$s = json_build_object('id', %1\$s_id, 'title', %1\$s_title) "
                .'WHERE %1$s IS NULL AND %1$s_id IS NOT NULL',
                $ref,
            ));
        }
        // 3) Снос старых индексов и колонок.
        foreach (['idx_report_project', 'idx_report_customer', 'idx_report_system'] as $index) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
        }
        foreach (['project_id', 'project_title', 'customer_id', 'customer_title', 'contractor_id', 'contractor_title', 'system_id', 'system_title'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report DROP COLUMN IF EXISTS %s', $column));
        }
        // 4) Индексы аналитики по id внутри JSON.
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_project ON report ((project->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_customer ON report ((customer->>'id'))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_system ON report ((system->>'id'))");
    }

    public function down(Schema $schema): void
    {
        foreach (['idx_report_project', 'idx_report_customer', 'idx_report_system'] as $index) {
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
        }
        foreach (['project', 'customer', 'contractor', 'system'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report DROP COLUMN IF EXISTS %s', $column));
        }
        foreach ([
            'project_id' => 'VARCHAR(36)', 'project_title' => 'VARCHAR(255)',
            'customer_id' => 'VARCHAR(36)', 'customer_title' => 'VARCHAR(255)',
            'contractor_id' => 'VARCHAR(36)', 'contractor_title' => 'VARCHAR(255)',
            'system_id' => 'VARCHAR(36)', 'system_title' => 'VARCHAR(255)',
        ] as $column => $type) {
            $this->addSql(sprintf('ALTER TABLE report ADD COLUMN IF NOT EXISTS %s %s DEFAULT NULL', $column, $type));
        }
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_project ON report (project_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_customer ON report (customer_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_report_system ON report (system_id)');
    }
}
