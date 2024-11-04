<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

readonly class ManufacturersFilter
{
    public function __construct(
        public ?string $title = null,
        public ?Pager  $pager = null,
    )
    {
    }
}
