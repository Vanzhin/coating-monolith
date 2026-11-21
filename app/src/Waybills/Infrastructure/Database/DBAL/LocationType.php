<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Waybills\Domain\Aggregate\Route\Location;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class LocationType extends JsonType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Location
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Expected a location object.');
        }

        return Location::fromArray($data);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null !== $value && !$value instanceof Location) {
            throw new \LogicException('Expected Location, got '.get_debug_type($value));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }
}
