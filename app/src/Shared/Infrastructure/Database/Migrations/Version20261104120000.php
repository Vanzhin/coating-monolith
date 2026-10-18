<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance D-проекция (Task 5, Вариант B): агрегат `ProfileCompliance` перестаёт материализовать норму —
 * персональные (off-norm) декларации переезжают в собственный JSONB-список `personal_obligations`
 * (VO {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\PersonalObligation}), норма отныне читается
 * только из `Requirement`. Бэкфилл переносит существующие декларации из старой проекции-таблицы
 * `compliance_tracked_obligation` (origin='personal') — с этого момента она мёртвые данные для записи (агрегат
 * больше её не ведёт), дроп — Task 7. Идемпотентно: колонка добавляется `IF NOT EXISTS`, бэкфилл трогает только
 * строки, где `personal_obligations IS NULL` (повторный прогон не задублирует).
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance D-проекция: personal_obligations (JSONB) на compliance_profile_compliance — бэкфилл персональных деклараций из старой compliance_tracked_obligation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_profile_compliance ADD COLUMN IF NOT EXISTS personal_obligations JSONB DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE compliance_profile_compliance pc
            SET personal_obligations = agg.rows
            FROM (
                SELECT profile_compliance_id,
                       jsonb_agg(jsonb_build_object(
                           'itemId', item_id,
                           'requirementId', requirement_id,
                           'label', label,
                           'type', type,
                           'cadence', cadence,
                           'quantity', quantity
                       )) AS rows
                FROM compliance_tracked_obligation
                WHERE origin = 'personal'
                GROUP BY profile_compliance_id
            ) agg
            WHERE pc.id = agg.profile_compliance_id
              AND pc.personal_obligations IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_profile_compliance DROP COLUMN IF EXISTS personal_obligations');
    }
}
