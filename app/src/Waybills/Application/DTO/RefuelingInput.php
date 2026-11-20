<?php

declare(strict_types=1);

namespace App\Waybills\Application\DTO;

use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use Brick\Math\BigDecimal;
use Symfony\Component\Uid\Uuid;

final readonly class RefuelingInput
{
    public function __construct(
        public ?Uuid $id,
        public \DateTimeImmutable $date,
        public FuelType $fuelType,
        public BigDecimal $quantity,
    ) {
    }
}
