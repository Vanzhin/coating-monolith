<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Человекочитаемая подпись одной оси фильтра: «Подложка» → «Углеродистая сталь». Coatings знает
 * заголовки своих enum-ов/фасет; внешние контексты (Inquiries) показывают это, не трогая домен.
 */
final readonly class FilterFacetLabel
{
    public function __construct(public string $label, public string $value)
    {
    }
}
