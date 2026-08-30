<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Страница «Химстойкость»: покрытия, стойкие к выбранным веществам, с пагинацией.
 * Мультивыбор — логика AND: покрытие проходит, только если стойко к КАЖДОМУ веществу.
 * includeAll=false — только стойкие (R/LR); true — все оценки (админ-управление).
 */
readonly class GetCoatingsBySubstanceQuery extends Query
{
    public function __construct(
        public StringCollection $substanceIds,
        public bool $includeAll,
        public int $page,
        public int $perPage,
    ) {
    }
}
