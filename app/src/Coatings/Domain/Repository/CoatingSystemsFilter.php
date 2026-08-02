<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;

/**
 * Полный набор фильтров для поиска систем покрытий. Все поля опциональны.
 * Compliance — мастер-каскад: category/durability имеют смысл только когда задан standard.
 */
final readonly class CoatingSystemsFilter
{
    /**
     * @param list<Substrate> $substrates
     * @param list<string>    $tagIds
     */
    public function __construct(
        public ?SearchQuery $search = null,
        public array $substrates = [],
        public ?ComplianceStandard $standard = null,
        public ?string $category = null,
        public ?string $durability = null,
        public array $tagIds = [],
        public ?RangeFilter $applicationMinTemp = null,
        public ?RangeFilter $minApplicationTimeAt20 = null,
        public CoatingSystemSort $sort = CoatingSystemSort::DEFAULT,
        public Pager $pager = new Pager(1, 20),
        // Legacy — до уборки в Task 12
        public ?string $titleLike = null,
        public ?Substrate $substrate = null,
    ) {
    }
}
