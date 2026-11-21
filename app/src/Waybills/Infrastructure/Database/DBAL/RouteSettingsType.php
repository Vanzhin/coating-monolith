<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class RouteSettingsType extends JsonType
{
    public function convertToPHPValue($value, AbstractPlatform $platform): ?RouteSettings
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }
        if (!is_array($data) || !isset($data['base'], $data['destinations']) || !is_array($data['base']) || !is_array($data['destinations']) || !array_is_list($data['destinations'])) {
            throw new \UnexpectedValueException('Invalid route settings.');
        }
        $destinations = [];
        foreach ($data['destinations'] as $item) {
            if (!is_array($item) || !isset($item['location'], $item['distance']) || !array_key_exists('enterprise', $item) || !is_array($item['location']) || !is_int($item['distance']) || (null !== $item['enterprise'] && !is_string($item['enterprise']))) {
                throw new \UnexpectedValueException('Invalid route destination.');
            }
            $destinations[] = new Destination(Location::fromArray($item['location']), $item['distance'], $item['enterprise']);
        }

        return new RouteSettings(Location::fromArray($data['base']), ...$destinations);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof RouteSettings) {
            throw new \LogicException('Expected RouteSettings, got '.get_debug_type($value));
        }

        return parent::convertToDatabaseValue([
            'base' => $value->base->jsonSerialize(),
            'destinations' => array_map(static fn (Destination $d): array => [
                'location' => $d->location->jsonSerialize(),
                'distance' => $d->distance,
                'enterprise' => $d->enterprise,
            ], $value->destinations()),
        ], $platform);
    }
}
