<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use JsonSerializable;

/**
 * Серия точек «время-при-температуре». Конкретные реализации могут добавлять
 * собственные инварианты (например, монотонность времени по росту температуры).
 */
interface TimeSeries extends JsonSerializable
{
    public function getPoint(int $temperatureAt): ?TimeAtTemperature;
}
