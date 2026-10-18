<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance D-проекция (Task 4, флип читателей): структурная периодичность на `compliance_obligation_projection`.
 * Исходная таблица (Task 1) хранила `cadence` только готовым label-снимком («ежегодно») — достаточно для
 * отображения, но форма «Выдать» (`/issue`) использует структурные kind/number/unit для живого клиентского
 * предпросмотра «след. выдача» (`data-cadence-*` → AJAX `/cabinet/compliance/next-due`). Формат — JSONB, зеркалит
 * существующую персистенцию VO `Cadence` на `compliance_tracked_obligation.cadence`
 * ({@see \App\Compliance\Infrastructure\Database\DBAL\CadenceType}, тот же DBAL-тип переиспользуется для чтения
 * этой колонки). Старая `cadence` (VARCHAR label) не трогается — обе колонки сосуществуют. Идемпотентно.
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance D-проекция: cadence_struct (JSONB) на compliance_obligation_projection — структурная периодичность для предпросмотра выдачи.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection ADD COLUMN IF NOT EXISTS cadence_struct JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_obligation_projection DROP COLUMN IF EXISTS cadence_struct');
    }
}
