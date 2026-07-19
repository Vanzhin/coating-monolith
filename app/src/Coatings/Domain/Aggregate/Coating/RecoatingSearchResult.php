<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class RecoatingSearchResult
{
    /**
     * @param StringCollection $matchedPath Список эталонных нормализованных ключей, которые удалось пройти
     */
    public function __construct(
        public DryingTimeSeries $series,
        public bool $isExactMatch,
        public StringCollection $matchedPath,
    ) {
    }
}
