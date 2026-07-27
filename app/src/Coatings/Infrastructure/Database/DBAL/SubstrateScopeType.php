<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class SubstrateScopeType extends JsonType
{
    public const NAME = 'substrate_scope';

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<Substrate>|null
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if (null === $value) {
            return null;
        }
        $decoded = parent::convertToPHPValue($value, $platform);

        return array_map(Substrate::from(...), $decoded);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return null;
        }

        return parent::convertToDatabaseValue(
            array_map(fn(Substrate $s) => $s->value, $value),
            $platform,
        );
    }
}
