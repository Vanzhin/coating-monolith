<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

/** Пол сотрудника — нужен только для подбора размеров СИЗ (мужская/женская линейка). */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    public function title(): string
    {
        return match ($this) {
            self::Male => 'мужской',
            self::Female => 'женский',
        };
    }
}
