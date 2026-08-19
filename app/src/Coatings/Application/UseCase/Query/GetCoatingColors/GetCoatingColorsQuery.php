<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingColors;

use App\Shared\Application\Query\Query;

final readonly class GetCoatingColorsQuery extends Query
{
    public function __construct(public string $coatingId)
    {
    }
}
