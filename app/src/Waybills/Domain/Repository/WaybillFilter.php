<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

final readonly class WaybillFilter
{
    public function __construct(
        public ?string $driverOwnerId = null,
        public ?string $vehicleId = null,
        public Pager $pager = new Pager(Pager::DEFAULT_PAGE, Pager::DEFAULT_LIMIT),
    ) {
    }
}
