<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Базовый DBAL-тип для VO, хранимых в JSONB. Подкласс задаёт класс VO и метод гидрации;
 * остальное (null-обработка, type-check, сериализация через JsonSerializable) — здесь.
 *
 * Контракт VO: implements \JsonSerializable + static fromArray(array): static.
 */
abstract class AbstractJsonObjectType extends JsonType
{
    /** Класс VO, который этот тип сериализует/гидрирует. */
    abstract protected function valueClass(): string;

    /** Гидрация VO из ассоциативного массива (обычно `static::valueClass()::fromArray($raw)`). */
    abstract protected function hydrate(array $raw): object;

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        $class = $this->valueClass();
        if (!$value instanceof $class) {
            throw new \InvalidArgumentException(sprintf('Ожидался %s, передан %s.', $class, is_object($value) ? $value::class : gettype($value)));
        }

        // JsonType::convertToDatabaseValue → json_encode сам зовёт jsonSerialize() рекурсивно.
        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?object
    {
        if (null === $value) {
            return null;
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException(sprintf('Для %s ожидается JSON-массив/объект.', $this->valueClass()));
        }

        return $this->hydrate($raw);
    }
}
