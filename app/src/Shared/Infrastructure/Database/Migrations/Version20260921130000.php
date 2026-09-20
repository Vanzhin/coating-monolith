<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Индекс по id подрядчика (jsonb `contractor->>'id'`) — ось аналитики, как у project/customer/system.
 * Был пропущен в Version20260919120000. Остальные json-колонки report уже jsonb.
 */
final class Version20260921130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Report: expression index on contractor->>id (parity with project/customer/system).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_report_contractor ON report ((contractor->>'id'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_report_contractor');
    }
}
