<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingColors;

use App\Coatings\Application\DTO\Colors\ColorDTO;

final readonly class GetCoatingColorsQueryResult
{
    /**
     * @param list<ColorDTO> $colors
     */
    public function __construct(
        public bool $isTintable,
        public array $colors,
    ) {
    }
}
