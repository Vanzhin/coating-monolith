<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Database\DBAL;

use App\Certificates\Domain\Aggregate\Document\Reference;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * DBAL-тип для коллекции ссылок документа (list<Reference>), хранимой в jsonb.
 * AbstractJsonObjectType не подходит — там один VO, а тут массив.
 */
final class DocumentReferencesType extends JsonType
{
    public const NAME = 'document_references';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @param list<Reference>|null $value
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Ожидался list<Reference>.');
        }

        // Reference implements JsonSerializable → json_encode сериализует каждую ссылку.
        return parent::convertToDatabaseValue(array_values($value), $platform);
    }

    /**
     * @return list<Reference>
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): array
    {
        if (null === $value) {
            return [];
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Ожидался JSON-массив ссылок.');
        }

        return array_map(
            static fn (array $item) => Reference::fromArray($item),
            array_values($raw),
        );
    }
}
