<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPagedProfiles;

use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedProfilesQuery extends Query
{
    public function __construct(public ProfilesFilter $filter)
    {
    }
}
