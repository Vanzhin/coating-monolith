<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * JSONB<list<string>> ↔ StringCollection. Читаем как коллекцию, пишем через
 * JsonSerializable (StringCollection умеет). Расширяет JsonType, чтобы получить
 * готовые SQL-декларации и корректную работу с jsonb на Postgres.
 */
final class StringCollectionType extends JsonType
{
    public const NAME = 'string_collection';

    public function convertToPHPValue($value, AbstractPlatform $platform): StringCollection
    {
        $arr = parent::convertToPHPValue($value, $platform);
        if (!is_array($arr)) {
            return new StringCollection();
        }
        return new StringCollection(...array_map('strval', array_values($arr)));
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        if ($value instanceof StringCollection) {
            return parent::convertToDatabaseValue($value->getList(), $platform);
        }
        return parent::convertToDatabaseValue($value ?? [], $platform);
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
