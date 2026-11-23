<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service;

use App\Waybills\Domain\Aggregate\Route\Destination;

final readonly class RouteFillOption
{
    public function __construct(
        public int $direction,
        public Destination $destination,
        public int $outwardDistance,
        public int $returnDistance,
    ) {
    }

    public function distance(): int
    {
        return $this->outwardDistance + $this->returnDistance;
    }

    public function deviation(): int
    {
        return abs($this->outwardDistance - $this->destination->distance)
            + abs($this->returnDistance - $this->destination->distance);
    }
}
