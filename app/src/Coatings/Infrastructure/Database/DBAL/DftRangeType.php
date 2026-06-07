<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

final class DftRangeType extends JsonType
{
    public const NAME = 'dft_range';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof DftRange) {
            throw new \InvalidArgumentException(sprintf(
                'Ожидался DftRange, передан %s.',
                is_object($value) ? $value::class : gettype($value),
            ));
        }

        $data = [
            'min' => $value->range->getMin(),
            'max' => $value->range->getMax(),
            'tds_dft' => $value->tdsDft,
            'type' => $value->type->value,
        ];

        return parent::convertToDatabaseValue($data, $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DftRange
    {
        if ($value === null) {
            return null;
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Для DftRange ожидается JSON-объект.');
        }

        return new DftRange(
            new PositiveNumberRange((int) $raw['min'], (int) $raw['max']),
            (int) $raw['tds_dft'],
            ThicknessType::from($raw['type']),
        );
    }
}
