<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class ConsumptionRateType extends JsonType
{
    public function convertToPHPValue($value, AbstractPlatform $platform): ?PositiveNumber
    {
        $number = parent::convertToPHPValue($value, $platform);
        if (null === $number) {
            return null;
        }
        if (!is_int($number) && !is_float($number)) {
            throw new \UnexpectedValueException('Expected a numeric consumption rate.');
        }

        return new PositiveNumber($number);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null !== $value && !$value instanceof PositiveNumber) {
            throw new \LogicException('Expected PositiveNumber, got '.get_debug_type($value));
        }

        return parent::convertToDatabaseValue($value?->value(), $platform);
    }
}
