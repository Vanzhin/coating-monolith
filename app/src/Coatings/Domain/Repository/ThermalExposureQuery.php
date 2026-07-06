<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Критерий поиска покрытий по температуре эксплуатации: «покрытие держит T °C
 * в среде E, опционально с учётом пиковых нагрузок».
 *
 * Семантика (совпадает с ThermalExposureLimits::covers):
 *   continuousMin ≤ T ≤ (includingPeak ? peakMax ?? continuousMax : continuousMax)
 */
final readonly class ThermalExposureQuery
{
    /** Разумные границы ввода, чтобы отсечь мусор. */
    private const MIN_TEMPERATURE = -200;
    private const MAX_TEMPERATURE = 1000;

    public function __construct(
        public int $temperature,
        public ThermalEnvironment $environment,
        public bool $includingPeak = false,
    ) {
        if ($temperature < self::MIN_TEMPERATURE || $temperature > self::MAX_TEMPERATURE) {
            throw new AppException(sprintf(
                'Температура фильтра должна быть от %d до %d °C.',
                self::MIN_TEMPERATURE,
                self::MAX_TEMPERATURE,
            ));
        }
    }
}
