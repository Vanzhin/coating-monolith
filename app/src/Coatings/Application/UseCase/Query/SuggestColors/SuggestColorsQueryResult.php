<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestColors;

use App\Coatings\Application\DTO\Colors\ColorDTO;

final readonly class SuggestColorsQueryResult
{
    /** @param list<ColorDTO> $colors */
    public function __construct(public array $colors)
    {
    }
}
