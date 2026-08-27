<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

class IssuersFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $title = null,
    ) {
    }
}
