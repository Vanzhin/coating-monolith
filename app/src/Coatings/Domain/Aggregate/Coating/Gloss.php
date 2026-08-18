<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

/**
 * Степень блеска покрытия — единственное значение на покрытие.
 */
enum Gloss: string
{
    case DEAD_MATTE = 'dead_matte';
    case MATTE = 'matte';
    case SEMI_MATTE = 'semi_matte';
    case SEMI_GLOSS = 'semi_gloss';
    case GLOSS = 'gloss';

    public function title(): string
    {
        return match ($this) {
            self::DEAD_MATTE => 'Глубокоматовый',
            self::MATTE => 'Матовый',
            self::SEMI_MATTE => 'Полуматовый',
            self::SEMI_GLOSS => 'Полуглянцевый',
            self::GLOSS => 'Глянцевый',
        };
    }
}
