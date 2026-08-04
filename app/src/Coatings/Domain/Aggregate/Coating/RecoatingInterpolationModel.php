<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Модель пересчёта минимального интервала перекрытия при переходе
 * с эталонной толщины покрытия (tdsDft) на фактическую толщину слоя в системе.
 * Задаётся у покрытия; по умолчанию LINEAR.
 */
enum RecoatingInterpolationModel: string
{
    /** Пропорционально толщине: чем толще слой, тем дольше сохнет. */
    case LINEAR = 'LINEAR';

    /** Константа: интервал не зависит от толщины. */
    case STEP = 'STEP';

    public function title(): string
    {
        return match ($this) {
            self::LINEAR => 'Линейная',
            self::STEP => 'Ступенчатая',
        };
    }

    /**
     * Пересчитывает интервал перекрытия под фактическую толщину слоя.
     *
     * @param int $sourceDft     эталонная толщина, для которой задан интервал (мкм)
     * @param int $targetDft     фактическая толщина слоя в системе (мкм)
     * @param int $sourceMinutes значение интервала при $sourceDft (минуты, >0)
     *
     * @throws AppException при некорректных входных значениях
     */
    public function interpolate(int $sourceDft, int $targetDft, int $sourceMinutes): int
    {
        if ($sourceDft <= 0) {
            throw new AppException('Эталонная толщина покрытия должна быть положительной.');
        }
        if ($targetDft <= 0) {
            throw new AppException('Фактическая толщина слоя должна быть положительной.');
        }
        if ($sourceMinutes <= 0) {
            throw new AppException('Интервал перекрытия должен быть положительным.');
        }

        return match ($this) {
            self::LINEAR => (int) round($sourceMinutes * $targetDft / $sourceDft),
            self::STEP => $sourceMinutes,
        };
    }
}
