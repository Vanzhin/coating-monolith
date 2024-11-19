<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoating;

use App\Shared\Application\Query\Query;

readonly class GetCoatingQuery extends Query
{
    public function __construct(public string $coatingId)
    {
    }
}
