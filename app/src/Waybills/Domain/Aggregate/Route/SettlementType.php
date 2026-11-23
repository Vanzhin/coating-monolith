<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

enum SettlementType: string
{
    case Village = 'village';
    case Selo = 'selo';
    case Settlement = 'settlement';
    case Khutor = 'khutor';
    case UrbanSettlement = 'urban_settlement';
    case City = 'city';

    public function label(): string
    {
        return match ($this) {
            self::Village => 'Деревня',
            self::Selo => 'Село',
            self::Settlement => 'Посёлок',
            self::Khutor => 'Хутор',
            self::UrbanSettlement => 'Посёлок городского типа',
            self::City => 'Город',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::Village => 'д.',
            self::Selo => 'с.',
            self::Settlement => 'п.',
            self::Khutor => 'х.',
            self::UrbanSettlement => 'пгт',
            self::City => 'г.',
        };
    }
}
