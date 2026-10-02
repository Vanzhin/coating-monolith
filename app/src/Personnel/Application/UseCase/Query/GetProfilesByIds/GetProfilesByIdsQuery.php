<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfilesByIds;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/** Профили по id (батч) — для обогащения строк дашборда ФИО/должностью/отделом. */
final readonly class GetProfilesByIdsQuery extends Query
{
    public function __construct(public StringCollection $ids)
    {
    }
}
