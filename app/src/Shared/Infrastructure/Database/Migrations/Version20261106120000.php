<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance D-проекция (финальный ревью, follow-up): ширина `cadence` на `compliance_obligation_projection`
 * была VARCHAR(32) (Task 1) — узко для некоторых label-снимков периодичности. Расширяем до VARCHAR(255),
 * как у остальных текстовых label-колонок проекции. Postgres расширяет VARCHAR без переписи таблицы и без
 * риска для существующих строк — безопасно повторять.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance D-проекция: cadence на compliance_obligation_projection — VARCHAR(32) → VARCHAR(255).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection ALTER COLUMN cadence TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        // Сужение обратно до VARCHAR(32) на проде с данными шире 32 символов обрезало бы их молча —
        // no-op безопаснее усечения при откате.
    }
}
