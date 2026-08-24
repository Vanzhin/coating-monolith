<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Sp28;

/**
 * Степень агрессивного воздействия среды по СП 28.13330.2017 (§9.1.1) — «среда» в маркировке.
 * Дробление слабоагрессивной на -1/-2 (только для газовых сред) не моделируем: в рулбуке оно
 * сворачивается в WEAK максимумом требований. NON_AGGRESSIVE — базовый уровень, наружу не выводится.
 */
enum SpAggressivity: string
{
    case NON_AGGRESSIVE = 'non_aggressive';
    case WEAK = 'weak';
    case MEDIUM = 'medium';
    case STRONG = 'strong';

    public function title(): string
    {
        return match ($this) {
            self::NON_AGGRESSIVE => 'Неагрессивная',
            self::WEAK => 'Слабоагрессивная',
            self::MEDIUM => 'Среднеагрессивная',
            self::STRONG => 'Сильноагрессивная',
        };
    }

    /** Компактная подпись для бейджа на карточке. */
    public function shortTitle(): string
    {
        return match ($this) {
            self::NON_AGGRESSIVE => 'Неагр.',
            self::WEAK => 'Слабоагр.',
            self::MEDIUM => 'Среднеагр.',
            self::STRONG => 'Сильноагр.',
        };
    }

    /** Порядок силы: чем выше, тем агрессивнее среда. Для выбора сильнейшей достижимой степени. */
    public function rank(): int
    {
        return match ($this) {
            self::NON_AGGRESSIVE => 0,
            self::WEAK => 1,
            self::MEDIUM => 2,
            self::STRONG => 3,
        };
    }

    /**
     * Значения степеней не ниже текущей (для фильтра «≥ выбранной»).
     *
     * @return list<string>
     */
    public function atOrAbove(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            if ($case->rank() >= $this->rank()) {
                $out[] = $case->value;
            }
        }

        return $out;
    }
}
