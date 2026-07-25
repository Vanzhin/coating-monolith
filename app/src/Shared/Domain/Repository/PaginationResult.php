<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

readonly class PaginationResult
{
    /**
     * @param list<object> $items
     */
    public function __construct(public array $items, public int $total)
    {
    }
}
