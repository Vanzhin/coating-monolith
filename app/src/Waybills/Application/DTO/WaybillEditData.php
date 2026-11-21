<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

use App\Waybills\Domain\Aggregate\Waybill\Waybill;

final readonly class WaybillEditData
{
    public function __construct(public Waybill $waybill, public int $version)
    {
    }
}
