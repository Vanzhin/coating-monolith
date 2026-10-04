<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\ListRequirements;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Repository\Pager;

readonly class ListRequirementsQuery extends Query
{
    public function __construct(public ?Pager $pager = null)
    {
    }
}
