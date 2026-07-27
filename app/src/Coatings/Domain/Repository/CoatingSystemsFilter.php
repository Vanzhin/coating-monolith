<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;

final readonly class CoatingSystemsFilter
{
    public function __construct(
        public ?string $titleLike = null,
        public ?Substrate $substrate = null,
    ) {
    }
}
