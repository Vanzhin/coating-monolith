<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem\Iso12944;

enum IsoCorrosivityCategory: string
{
    case C1  = 'C1';
    case C2  = 'C2';
    case C3  = 'C3';
    case C4  = 'C4';
    case C5  = 'C5';
    case CX  = 'CX';
    case IM1 = 'Im1';
    case IM2 = 'Im2';
    case IM3 = 'Im3';

    public function title(): string
    {
        return $this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::C1  => 'Очень низкая',
            self::C2  => 'Низкая',
            self::C3  => 'Средняя',
            self::C4  => 'Высокая',
            self::C5  => 'Очень высокая',
            self::CX  => 'Экстремальная',
            self::IM1 => 'Погружение в пресную воду',
            self::IM2 => 'Погружение в морскую или слабоминерализованную воду',
            self::IM3 => 'Погружение в грунт',
        };
    }
}
