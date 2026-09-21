<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\DBAL;

use App\Shared\Domain\ValueObject\DateTimeInterval;

/**
 * Хранит интервал дат {from,to} как JSON. null-колонка = интервал не задан.
 * Регистрируется как тип `datetime_interval` в doctrine.yaml.
 */
final class DateTimeIntervalType extends AbstractJsonObjectType
{
    protected function valueClass(): string
    {
        return DateTimeInterval::class;
    }

    protected function hydrate(array $raw): DateTimeInterval
    {
        return DateTimeInterval::fromArray($raw);
    }
}
