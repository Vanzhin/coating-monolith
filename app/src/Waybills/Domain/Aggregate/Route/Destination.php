<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class Destination
{
    public ?string $enterprise;

    public function __construct(
        public Location $location,
        public int $distance,
        ?string $enterprise = null,
    ) {
        if ($distance <= 0) {
            throw new AppException('Расстояние до точки должно быть положительным числом километров.');
        }

        $enterprise = null === $enterprise ? null : trim($enterprise);
        $this->enterprise = '' === $enterprise ? null : $enterprise;
    }
}
