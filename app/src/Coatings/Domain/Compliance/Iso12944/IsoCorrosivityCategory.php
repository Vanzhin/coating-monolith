<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

enum IsoCorrosivityCategory: string
{
    case C1 = 'C1';
    case C2 = 'C2';
    case C3 = 'C3';
    case C4 = 'C4';
    case C5 = 'C5';
    case CX = 'CX';
    case IM1 = 'Im1';
    case IM2 = 'Im2';
    case IM3 = 'Im3';
    case IM4 = 'Im4';

    public function title(): string
    {
        return $this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::C1 => 'Очень низкая',
            self::C2 => 'Низкая',
            self::C3 => 'Средняя',
            self::C4 => 'Высокая',
            self::C5 => 'Очень высокая',
            self::CX => 'Экстремальная',
            self::IM1 => 'Погружение в пресную воду',
            self::IM2 => 'Погружение в морскую или слабоминерализованную воду',
            self::IM3 => 'Погружение в грунт',
            self::IM4 => 'Постоянное погружение в морскую воду',
        };
    }

    public function family(): string
    {
        return match ($this) {
            self::C1, self::C2, self::C3, self::C4, self::C5, self::CX => 'atmospheric',
            self::IM1, self::IM2, self::IM3, self::IM4 => 'immersion',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::C1 => 1,
            self::C2 => 2,
            self::C3 => 3,
            self::C4 => 4,
            self::C5 => 5,
            self::CX => 6,
            self::IM1 => 1,
            self::IM2 => 2,
            self::IM3 => 3,
            self::IM4 => 4,
        };
    }

    /**
     * Значения категорий той же семьи (атмосферные vs погружные), равные или большие
     * текущей, в порядке возрастания. Используется для фильтра поиска «≥ выбранного»:
     * система с максимумом C5 подходит и под фильтр C3.
     *
     * @return list<string>
     */
    public function atOrAboveInFamily(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            if ($case->family() === $this->family() && $case->rank() >= $this->rank()) {
                $result[] = $case->value;
            }
        }

        return $result;
    }
}
