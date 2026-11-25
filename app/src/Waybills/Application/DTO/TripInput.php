<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use Symfony\Component\Uid\Uuid;

final readonly class TripInput
{
    public function __construct(
        public ?Uuid $id,
        public \DateTimeImmutable $date,
        public Destination $destination,
        public RouteLogOrigin $origin = RouteLogOrigin::Manual,
        public ?Uuid $pairId = null,
        public ?\DateTimeImmutable $originalDate = null,
        public ?Destination $originalDestination = null,
    ) {
    }
}
