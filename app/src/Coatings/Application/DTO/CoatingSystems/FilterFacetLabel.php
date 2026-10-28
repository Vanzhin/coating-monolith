<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Человекочитаемая подпись одной оси фильтра: «Подложка» → «Углеродистая сталь». Coatings знает
 * заголовки своих enum-ов/фасет; внешние контексты (Inquiries) показывают это, не трогая домен.
 * keys — исходные ключи примитивного входа, из которых собрана строка (чтобы потребитель мог
 * сопоставить строку с провенансом значения: что от модели, что поправил инженер).
 */
final readonly class FilterFacetLabel
{
    /** @param list<string> $keys */
    public function __construct(
        public string $label,
        public string $value,
        public array $keys = [],
    ) {
    }
}
