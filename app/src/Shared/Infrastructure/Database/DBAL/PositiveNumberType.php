<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class PositiveNumberType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getFloatDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?PositiveNumber
    {
        if (null === $value || $value instanceof PositiveNumber) {
            return $value;
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new \UnexpectedValueException('Expected a numeric value.');
        }

        return new PositiveNumber((float) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): int|float|null
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof PositiveNumber) {
            throw new \LogicException('Expected PositiveNumber, got '.get_debug_type($value));
        }

        return $value->value();
    }
}
