<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Database\DBAL;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class CasNumberType extends Type
{
    public const NAME = 'cas_number';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'VARCHAR(15)';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?CasNumber
    {
        return null === $value ? null : CasNumber::fromString((string) $value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof CasNumber) {
            throw new \LogicException('Expected CasNumber, got '.get_debug_type($value));
        }

        return $value->value;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
