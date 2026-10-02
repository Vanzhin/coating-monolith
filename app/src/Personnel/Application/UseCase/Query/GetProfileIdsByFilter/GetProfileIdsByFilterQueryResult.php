<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileIdsByFilter;

use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class GetProfileIdsByFilterQueryResult
{
    public function __construct(public StringCollection $profileIds)
    {
    }
}
