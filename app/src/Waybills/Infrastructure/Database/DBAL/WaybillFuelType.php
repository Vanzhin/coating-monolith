<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Database\DBAL\ExactDateTimeType;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class WaybillFuelType extends JsonType
{
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Fuel
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }
        if (!is_array($data) || !isset($data['initial'], $data['type']) || !array_key_exists('remaining', $data) || !array_key_exists('refuelings', $data)
            || (!self::isNumber($data['initial']) && (!is_string($data['initial']) || !is_numeric($data['initial']))) || (null !== $data['remaining'] && !self::isNumber($data['remaining']))
            || !is_string($data['type']) || (null !== $data['refuelings'] && !is_array($data['refuelings']))) {
            throw new \UnexpectedValueException('Invalid waybill fuel.');
        }

        $storedRefuelings = $data['refuelings'];
        if (null === $storedRefuelings) {
            return new Fuel($data['initial'], $data['remaining'], null, $data['type']);
        }
        if (!isset($storedRefuelings['total'], $storedRefuelings['entries'])
            || !self::isNumber($storedRefuelings['total']) || $storedRefuelings['total'] < 0
            || !is_array($storedRefuelings['entries']) || !array_is_list($storedRefuelings['entries'])) {
            throw new \UnexpectedValueException('Invalid waybill refuelings.');
        }

        $entries = [];
        foreach ($storedRefuelings['entries'] as $entry) {
            if (!is_array($entry) || !isset($entry['date'], $entry['quantity'])
                || !is_string($entry['date']) || !self::isNumber($entry['quantity'])) {
                throw new \UnexpectedValueException('Invalid waybill refueling.');
            }
            $entries[] = new Refueling(ExactDateTimeType::parse($entry['date']), new PositiveNumber($entry['quantity']));
        }

        $total = $storedRefuelings['total'];
        $refuelings = new Refuelings($total > 0 ? new PositiveNumber($total) : null, ...$entries);
        if ([] !== $entries && $refuelings->total() != $total) {
            throw new \UnexpectedValueException('Waybill refueling total does not match its entries.');
        }

        return new Fuel($data['initial'], $data['remaining'], $refuelings, $data['type']);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof Fuel) {
            throw new \LogicException('Expected Fuel, got '.get_debug_type($value));
        }

        $refuelings = $value->refuelingsOrNull();

        return parent::convertToDatabaseValue([
            'initial' => $value->initial(),
            'remaining' => $value->remainingOrNull(),
            'type' => $value->type(),
            'refuelings' => null === $refuelings ? null : [
                'total' => $refuelings->total(),
                'entries' => array_map(static fn (Refueling $entry): array => [
                    'date' => ExactDateTimeType::format($entry->date()),
                    'quantity' => $entry->quantity()->value(),
                ], $refuelings->entries()),
            ],
        ], $platform);
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
