<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ИНН контрагента (Деплой 2): фиксируем инвариант «у каждого контрагента есть уникальный ИНН» на уровне
 * БД — колонка `tin` → NOT NULL, partial-индекс → полный unique. ЗАПУСКАТЬ ТОЛЬКО после бэкфилла: если
 * найдётся контрагент без ИНН, миграция падает с внятной ошибкой (не переводит молча). См. Деплой 1
 * (Version20260925120000) и docs/plans/counterparty-tin-2.md.
 */
final class Version20260925130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reports_counterparty.tin → NOT NULL + полный unique (Деплой 2 ИНН, после бэкфилла).';
    }

    public function up(Schema $schema): void
    {
        // Страховка: не трогаем схему, пока есть контрагенты без ИНН — иначе NOT NULL упал бы посреди дела.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM reports_counterparty WHERE tin IS NULL) THEN
                    RAISE EXCEPTION 'Есть контрагенты без ИНН — заполните reports_counterparty.tin перед Деплоем 2 (NOT NULL).';
                END IF;
            END $$;
        SQL);
        $this->addSql('DROP INDEX IF EXISTS uniq_reports_counterparty_tin');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_counterparty_tin ON reports_counterparty (tin)');
        $this->addSql('ALTER TABLE reports_counterparty ALTER COLUMN tin SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reports_counterparty ALTER COLUMN tin DROP NOT NULL');
        $this->addSql('DROP INDEX IF EXISTS uniq_reports_counterparty_tin');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_counterparty_tin ON reports_counterparty (tin) WHERE tin IS NOT NULL');
    }
}
