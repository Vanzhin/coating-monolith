<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\Aggregate\ProfileCompliance\PersonalObligation;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * DBAL-тип для персональных (off-norm) обязанностей человека (list<PersonalObligation>), хранимых в jsonb.
 * Зеркалит {@see RequirementItemsType}, но без дискриминатора типа — класс один, полиморфизма здесь нет.
 */
final class PersonalObligationsType extends JsonType
{
    public const NAME = 'compliance_personal_obligations';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @param list<PersonalObligation>|null $value
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Ожидался list<PersonalObligation>.');
        }

        $rows = array_map(
            static fn (PersonalObligation $obligation): array => $obligation->jsonSerialize(),
            array_values($value),
        );

        return parent::convertToDatabaseValue($rows, $platform);
    }

    /**
     * @return list<PersonalObligation>
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): array
    {
        if (null === $value) {
            return [];
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Ожидался JSON-массив персональных обязанностей.');
        }

        return array_map(
            static fn (array $row): PersonalObligation => PersonalObligation::fromArray($row),
            array_values($raw),
        );
    }
}
