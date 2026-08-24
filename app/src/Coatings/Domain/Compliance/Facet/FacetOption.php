<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Facet;

/**
 * Один вариант оси фасета: хранимое значение + человекочитаемая подпись для фильтра.
 */
final readonly class FacetOption
{
    public function __construct(
        public string $value,
        public string $title,
    ) {
    }
}
