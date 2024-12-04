<?php

declare(strict_types=1);

namespace App\Proposals\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

readonly class GeneralProposalInfoFilter
{

    public function __construct(
        public ?string $search = null,
        public ?Pager  $pager = null,
    )
    {
    }
}
