<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

class ProjectsFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $title = null,
    ) {
    }
}
