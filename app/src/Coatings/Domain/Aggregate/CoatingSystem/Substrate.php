<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

enum Substrate: string
{
    case STEEL_CARBON = 'steel_carbon';
    case STEEL_GALVANIZED = 'steel_galvanized';
    case STEEL_METALLIZED = 'steel_metallized';
    case STAINLESS_STEEL = 'stainless_steel';
    case CONCRETE = 'concrete';
    case ALUMINUM = 'aluminum';
    case EXISTING_COATING = 'existing_coating';

    public function title(): string
    {
        return match ($this) {
            self::STEEL_CARBON => 'Углеродистая сталь',
            self::STEEL_GALVANIZED => 'Оцинкованная сталь',
            self::STEEL_METALLIZED => 'Сталь с термически напылённым металлом',
            self::STAINLESS_STEEL => 'Нержавеющая сталь',
            self::CONCRETE => 'Бетон',
            self::ALUMINUM => 'Алюминий',
            self::EXISTING_COATING => 'Существующее покрытие',
        };
    }

    public function description(): string
    {
        return $this->title();
    }
}
