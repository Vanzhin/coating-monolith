<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

readonly class CoatingsFilter
{
    //todo расширить фильтр

    public function __construct(
        public ?string $title = null,
        public ?Pager  $pager = null,
    )
    {
    }
}
