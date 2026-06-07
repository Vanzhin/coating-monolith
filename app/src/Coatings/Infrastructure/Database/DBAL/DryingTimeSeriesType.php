<?php
declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class DryingTimeSeriesType extends JsonType
{
    public const NAME = 'drying_time_series';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof DryingTimeSeries) {
            throw new \InvalidArgumentException(sprintf(
                'Ожидался DryingTimeSeries, передан %s.',
                is_object($value) ? $value::class : gettype($value),
            ));
        }
        return parent::convertToDatabaseValue($value->jsonSerialize(), $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DryingTimeSeries
    {
        if ($value === null) {
            return null;
        }
        $rows = parent::convertToPHPValue($value, $platform);
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('Для DryingTimeSeries ожидается JSON-массив.');
        }
        $points = array_map(
            fn(array $row) => new TimeAtTemperature(
                (int) $row['temperature_at'],
                (float) $row['time_in_minutes'],
            ),
            $rows,
        );
        return new DryingTimeSeries(...$points);
    }
}
