<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;

final class PreciseDateTimeTzType extends DateTimeTzImmutableType
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if ($platform instanceof PostgreSQLPlatform) {
            return 'TIMESTAMP(6) WITH TIME ZONE';
        }

        return parent::getSQLDeclaration($column, $platform);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof \DateTimeImmutable) {
            throw new \LogicException('Expected DateTimeImmutable, got '.get_debug_type($value));
        }

        return $value->format('Y-m-d H:i:s.uP');
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Expected a date and time with a timezone.');
        }

        return new \DateTimeImmutable($value);
    }
}
