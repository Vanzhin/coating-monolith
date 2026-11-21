<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

use App\Waybills\Domain\Aggregate\Route\Destination;
use Symfony\Component\Uid\Uuid;

final readonly class TripInput
{
    public function __construct(
        public ?Uuid $id,
        public \DateTimeImmutable $date,
        public Destination $destination,
    ) {
    }
}
