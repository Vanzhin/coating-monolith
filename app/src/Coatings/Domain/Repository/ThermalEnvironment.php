<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

/**
 * Среда термического воздействия для поиска. Указывает, к какой из двух
 * колонок ThermalExposureLimits применять критерий (сухое тепло / погружение).
 */
enum ThermalEnvironment: string
{
    case DRY_HEAT = 'dry_heat';
    case IMMERSION = 'immersion';

    public function title(): string
    {
        return match ($this) {
            self::DRY_HEAT => 'Сухой нагрев',
            self::IMMERSION => 'Погружение',
        };
    }
}
