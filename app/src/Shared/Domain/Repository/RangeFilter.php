<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Диапазон-фасет для фильтра. Ровно одна или обе границы обязательны:
 *  - только from   → «от N и выше»;
 *  - только to     → «до N включительно»;
 *  - обе указаны   → валидируем from ≤ to.
 *
 * Обе null — конструктор кидает: сам факт создания RangeFilter означает
 * «я хочу фильтровать по этому полю»; если фильтр не нужен — не создавай
 * RangeFilter, передавай null в контейнер фильтра.
 *
 * Отдельный тип от NumberRange/PositiveNumberRange: те требуют обе границы
 * (это бизнес-VO для «валидного диапазона значения»), а мы описываем
 * ОДНОСТОРОННЕЕ или ДВУСТОРОННЕЕ ограничение при поиске.
 */
final readonly class RangeFilter
{
    public function __construct(
        public ?int $from = null,
        public ?int $to = null,
    ) {
        if ($from === null && $to === null) {
            throw new AppException('Диапазон-фильтр требует хотя бы одну границу.');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new AppException(sprintf(
                'Минимум диапазона (%d) не может быть больше максимума (%d).',
                $from,
                $to,
            ));
        }
    }

    /**
     * Фабрика: null → null (нет фильтра), иначе валидный RangeFilter.
     * Удобно для конструирования из query-параметров: `tryFromNullable((int) $from, (int) $to)`.
     */
    public static function tryFromNullable(?int $from, ?int $to): ?self
    {
        if ($from === null && $to === null) {
            return null;
        }
        return new self($from, $to);
    }
}
