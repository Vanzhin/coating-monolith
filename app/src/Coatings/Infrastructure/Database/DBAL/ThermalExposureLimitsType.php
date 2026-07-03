<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\ThermalExposureLimits;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class ThermalExposureLimitsType extends AbstractJsonObjectType
{
    public const NAME = 'thermal_exposure_limits';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return ThermalExposureLimits::class;
    }

    protected function hydrate(array $raw): ThermalExposureLimits
    {
        return ThermalExposureLimits::fromArray($raw);
    }
}
