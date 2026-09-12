<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\ThermalExposureLimitsDTO;
use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;

/**
 * Собирает доменный ThermalExposureLimits из транспортного DTO (каркас для конструктора VO,
 * без бизнес-правил — они в домене). Нет DTO или все четыре поля пусты → null: пределы не
 * задокументированы (при UPDATE существующая запись затирается в null). Попарные инварианты
 * (min<max, peak>max, duration>0) кидает сам VO.
 */
final readonly class ThermalExposureLimitsBuilder
{
    public function build(?ThermalExposureLimitsDTO $dto): ?ThermalExposureLimits
    {
        if (null === $dto) {
            return null;
        }

        $allEmpty = null === $dto->continuous_min
            && null === $dto->continuous_max
            && null === $dto->peak_max
            && null === $dto->peak_duration_minutes;
        if ($allEmpty) {
            return null;
        }

        return new ThermalExposureLimits(
            $dto->continuous_min,
            $dto->continuous_max,
            $dto->peak_max,
            $dto->peak_duration_minutes,
        );
    }
}
