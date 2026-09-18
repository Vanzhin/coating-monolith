<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ссылки-снимки отчёта: project/customer/contractor/system как пары id+title (id — связь/аналитика,
 * title — замороженный снимок для истории/офлайна). Индексы по id — оси аналитики.
 * Идемпотентно: ADD COLUMN / INDEX IF NOT EXISTS.
 */
final class Version20260918160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add snapshot references (project/customer/contractor/system) to report.';
    }

    public function up(Schema $schema): void
    {
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

    public function down(Schema $schema): void
    {
        foreach (['project_id', 'project_title', 'customer_id', 'customer_title', 'contractor_id', 'contractor_title', 'system_id', 'system_title'] as $column) {
            $this->addSql(sprintf('ALTER TABLE report DROP COLUMN IF EXISTS %s', $column));
        }
    }
}
