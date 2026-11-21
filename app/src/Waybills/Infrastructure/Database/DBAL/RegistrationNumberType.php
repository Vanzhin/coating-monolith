<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Database\DBAL;

use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

final class RegistrationNumberType extends TextType
{
    public function convertToPHPValue($value, AbstractPlatform $platform): ?RegistrationNumber
    {
        return null === $value ? null : new RegistrationNumber((string) $value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof RegistrationNumber) {
            throw new \LogicException('Expected RegistrationNumber, got '.get_debug_type($value));
        }

        return $value->value();
    }
}
