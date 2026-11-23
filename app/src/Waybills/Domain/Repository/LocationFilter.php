<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

final readonly class LocationFilter
{
    public function __construct(public ?string $ownerId = null, public ?string $search = null, public ?Pager $pager = null)
    {
    }
}
