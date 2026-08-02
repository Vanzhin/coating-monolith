<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

/**
 * Результат поиска на read-side через DBAL Finder.
 * Содержит только id-строки (UUID) и общее количество без фасетной пагинации.
 * Hydration агрегатов — отдельно через findByIds.
 */
final readonly class SearchResult
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public array $ids,
        public int $total,
    ) {
    }
}
