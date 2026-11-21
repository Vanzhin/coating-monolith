<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

final readonly class VehicleFilter
{
    public function __construct(
        public ?string $search = null,
        public Pager $pager = new Pager(Pager::DEFAULT_PAGE, Pager::DEFAULT_LIMIT),
        public ?string $driverOwnerId = null,
    ) {
    }
}
