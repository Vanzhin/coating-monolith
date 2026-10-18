<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance D-проекция (Task 7, contract): дроп старой `compliance_tracked_obligation`. Read-сторона (дашборд/
 * карточка/сканер due-soon) перешла на `compliance_obligation_projection` ещё в Task 1-6 (новая таблица —
 * единственный источник проекции), персональные декларации перенесены на агрегат отдельным JSONB-полем
 * (Task 5, `personal_obligations`). Старая таблица с этого момента мёртвые данные — ни читателя, ни писателя
 * у неё больше нет (доменная сущность-носитель удалена, её контракт-ключ вынесен в
 * {@see \App\Compliance\Domain\ValueObject\ObligationKey}).
 *
 * Физический DROP старой таблицы отложен: сносится отдельной поздней миграцией ПОСЛЕ подтверждения
 * прод-паритета новой проекции; до тех пор старая таблица — фолбэк. На момент follow-up прод эту версию ещё
 * не применял — no-op меняет только будущее применение; окружения, где она уже прогнана, повторно не выполнятся
 * (ключуется версией в `migration_versions`).
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance D-проекция: дроп старой compliance_tracked_obligation отложен (фолбэк до прод-паритета).';
    }

    public function up(Schema $schema): void
    {
        // No-op: см. докблок класса — физический DROP ships отдельной позднейшей миграцией.
    }

    public function down(Schema $schema): void
    {
        // No-op — зеркалит up(), ничего не сносили.
    }
}
