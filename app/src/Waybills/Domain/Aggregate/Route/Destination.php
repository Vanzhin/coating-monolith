<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class Destination
{
    public Location $location;
    public ?string $enterprise;

    public function __construct(
        Location $location,
        public int $distance,
        ?string $enterprise = null,
    ) {
        if ($distance <= 0) {
            throw new AppException('Расстояние до точки должно быть положительным числом километров.');
        }

        $this->location = null === $enterprise ? $location : $location->withEnterprise($enterprise);
        $this->enterprise = $this->location->enterprise;
    }
}
