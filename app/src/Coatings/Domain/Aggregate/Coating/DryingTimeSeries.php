<?php
declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\ValueObject\Series;
use App\Shared\Domain\Aggregate\ValueObject\SeriesPoint;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class DryingTimeSeries extends Series
{
    public function __construct(TimeAtTemperature ...$points)
    {
        parent::__construct(...$points);
    }

    /**
     * При росте температуры время сушки не должно расти.
     */
    protected function validate(): void
    {
        $previous = null;
        foreach ($this->points as $point) {
            if ($previous !== null && $point->timeInMinutes > $previous->timeInMinutes) {
                throw new AppException(sprintf(
                    'При %d°C время сушки %g мин не может быть больше, чем при %d°C — %g мин.',
                    $point->temperatureAt, $point->timeInMinutes,
                    $previous->temperatureAt, $previous->timeInMinutes,
                ));
            }
            $previous = $point;
        }
    }

    protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint
    {
        return new TimeAtTemperature((int) $key, (float) $value, $isCalculated);
    }
}
