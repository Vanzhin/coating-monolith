<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Результат поиска на read-side через DBAL Finder.
 * Содержит только id-строки (UUID) и общее количество без фасетной пагинации.
 * Hydration агрегатов — отдельно через findByIds.
 */
final readonly class SearchResult
{
    public function __construct(
        public StringCollection $ids,
        public int $total,
    ) {
    }
}
