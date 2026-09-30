<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

/** Bag-of-fields списка требований. Расширяется полями (напр. фильтр по типу), а не новыми методами. */
class RequirementsFilter
{
    public function __construct(
        public ?Pager $pager = null,
    ) {
    }
}
