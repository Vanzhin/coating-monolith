<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\FindCoatingSystemById;

use App\Shared\Application\Query\Query;

readonly class FindCoatingSystemByIdQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
