<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class DryingTimeSeriesType extends AbstractJsonObjectType
{
    public const NAME = 'drying_time_series';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return DryingTimeSeries::class;
    }

    protected function hydrate(array $raw): DryingTimeSeries
    {
        return DryingTimeSeries::fromArray($raw);
    }
}
