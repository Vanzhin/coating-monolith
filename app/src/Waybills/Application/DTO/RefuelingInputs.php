<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

final readonly class RefuelingInputs
{
    /** @var list<RefuelingInput> */
    public array $items;

    public function __construct(RefuelingInput ...$items)
    {
        $this->items = $items;
    }
}
