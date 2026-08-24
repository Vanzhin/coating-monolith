<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

enum IsoDurability: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case VERY_HIGH = 'VERY_HIGH';

    public function title(): string
    {
        return match ($this) {
            self::LOW => 'L',
            self::MEDIUM => 'M',
            self::HIGH => 'H',
            self::VERY_HIGH => 'VH',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LOW => 'Низкая (менее 7 лет)',
            self::MEDIUM => 'Средняя (от 7 до 15 лет)',
            self::HIGH => 'Высокая (от 15 до 25 лет)',
            self::VERY_HIGH => 'Очень высокая (более 25 лет)',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::VERY_HIGH => 4,
        };
    }

    /**
     * Значения долговечности, равные или большие текущей, в порядке возрастания.
     * Используется для фильтра поиска «≥ выбранного»: система с максимумом VH
     * подходит и под фильтр HIGH.
     *
     * @return list<string>
     */
    public function atOrAbove(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            if ($case->rank() >= $this->rank()) {
                $result[] = $case->value;
            }
        }

        return $result;
    }
}
