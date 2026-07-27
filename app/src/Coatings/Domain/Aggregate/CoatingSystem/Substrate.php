<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

enum Substrate: string
{
    case STEEL_CARBON = 'steel_carbon';
    case STEEL_GALVANIZED = 'steel_galvanized';
    case STEEL_METALLIZED = 'steel_metallized';
    case CONCRETE = 'concrete';
    case ALUMINUM = 'aluminum';

    public function title(): string
    {
        return match ($this) {
            self::STEEL_CARBON => 'Углеродистая сталь',
            self::STEEL_GALVANIZED => 'Оцинкованная сталь',
            self::STEEL_METALLIZED => 'Сталь с термически напылённым металлом',
            self::CONCRETE => 'Бетон',
            self::ALUMINUM => 'Алюминий',
        };
    }

    public function description(): string
    {
        return $this->title();
    }
}
