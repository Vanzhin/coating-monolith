<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class BigDecimalType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDecimalTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BigDecimal
    {
        if (null === $value || $value instanceof BigDecimal) {
            return $value;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new \UnexpectedValueException('Expected an exact decimal value.');
        }

        return BigDecimal::of($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof BigDecimal) {
            throw new \LogicException('Expected BigDecimal, got '.get_debug_type($value));
        }

        return (string) $value;
    }
}
