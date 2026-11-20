<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

final readonly class ProfileFilter
{
    public function __construct(public ?string $ownerId = null, public ?Pager $pager = null)
    {
    }
}
