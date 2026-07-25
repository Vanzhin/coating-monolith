<?php

namespace App\Shared\Domain\Trait;

trait EnumToArray
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * @return list<string|int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string|int, string>
     */
    public static function array(): array
    {
        return array_combine(self::values(), self::names());
    }
}
