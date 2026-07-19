<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

readonly class SubstancesFilter
{
    public function __construct(
        public ?string $search = null,
        public ?Pager  $pager = null,
        public ?string $cas = null,
    ) {}
}
