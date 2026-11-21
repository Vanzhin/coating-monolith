<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Database\DBAL\ExactDateTimeType;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class WaybillPeriodsType extends JsonType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Ожидается список периодов путевого листа.');
        }

        $periods = [];
        foreach ($value as $period) {
            if (!$period instanceof WaybillPeriod) {
                throw new \InvalidArgumentException('Ожидается период путевого листа.');
            }
            $periods[] = [
                'from' => ExactDateTimeType::format($period->from()),
                'to' => ExactDateTimeType::format($period->to()),
            ];
        }

        return parent::convertToDatabaseValue($periods, $platform);
    }

    /** @return list<WaybillPeriod>|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new \UnexpectedValueException('Некорректный список периодов путевого листа.');
        }

        $periods = [];
        foreach ($data as $period) {
            if (!is_array($period) || !isset($period['from'], $period['to']) || !is_string($period['from']) || !is_string($period['to'])) {
                throw new \UnexpectedValueException('Некорректный период путевого листа.');
            }
            $periods[] = WaybillPeriod::fromDates(ExactDateTimeType::parse($period['from']), ExactDateTimeType::parse($period['to']));
        }

        return $periods;
    }
}
