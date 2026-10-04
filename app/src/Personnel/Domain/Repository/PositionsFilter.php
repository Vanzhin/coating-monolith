<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

class PositionsFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $title = null,
    ) {
    }
}
