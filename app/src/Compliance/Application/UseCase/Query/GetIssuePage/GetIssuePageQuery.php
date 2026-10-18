<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

use App\Shared\Application\Query\Query;

/** Карточка требования на `/issue`: позиции текущей нормы + сопутствующие блоки (списание/инструктаж/№ карточки). */
readonly class GetIssuePageQuery extends Query
{
    public function __construct(public string $profileId, public string $requirementId)
    {
    }
}
