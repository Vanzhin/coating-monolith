<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

/** @extends AbstractRange<int> */
readonly class IntRange extends AbstractRange
{
    public function __construct(int $start, int $end)
    {
        parent::__construct($start, $end);
    }

    public function length(): int
    {
        return $this->end() - $this->start();
    }
}
