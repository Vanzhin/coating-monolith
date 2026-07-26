<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class SurfacePreparationType extends JsonType
{
    public const NAME = 'surface_preparation';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?SurfacePreparation
    {
        if (null === $value) {
            return null;
        }
        $data = parent::convertToPHPValue($value, $platform);
        return SurfacePreparation::fromArray($data);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return null;
        }
        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }
}
