<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

class CoatingTagsFilter
{
    /** @var list<string|null> */
    public array $types = [];

    public function __construct(
        public ?Pager $pager = null,
        public ?string $title = null,
        ?string ...$types,
    ) {
        $this->types = $types;
    }
}
