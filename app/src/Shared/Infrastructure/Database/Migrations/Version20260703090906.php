<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Добавляем две nullable jsonb-колонки для температурных пределов эксплуатации:
 *  dry_heat_exposure   — сухое тепло (окружающая среда).
 *  immersion_exposure  — погружение (жидкость).
 *
 * Формат VO ThermalExposureLimits:
 *   {continuous_min, continuous_max, peak_max?, peak_duration_minutes?}
 *
 * null-значение на уровне колонки означает «не задокументировано» / «не рассчитано
 * на погружение». Для существующих строк оба NULL по умолчанию — никакого backfill'a.
 */
final class Version20260703090906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coatings_coating.dry_heat_exposure and immersion_exposure jsonb columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN dry_heat_exposure jsonb NULL');
        $this->addSql('ALTER TABLE coatings_coating ADD COLUMN immersion_exposure jsonb NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN immersion_exposure');
        $this->addSql('ALTER TABLE coatings_coating DROP COLUMN dry_heat_exposure');
    }
}
