<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ИНН контрагента (Деплой 1): nullable-колонка `tin` + PARTIAL unique-индекс (уникальность только среди
 * заполненных — legacy-контрагенты без ИНН не конфликтуют). Деплой 2 переведёт колонку в NOT NULL и
 * заменит индекс на полный. Идемпотентно (IF NOT EXISTS).
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reports_counterparty.tin: nullable VARCHAR(12) + partial unique index (Деплой 1 ИНН).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reports_counterparty ADD COLUMN IF NOT EXISTS tin VARCHAR(12) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_counterparty_tin ON reports_counterparty (tin) WHERE tin IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_reports_counterparty_tin');
        $this->addSql('ALTER TABLE reports_counterparty DROP COLUMN IF EXISTS tin');
    }
}
