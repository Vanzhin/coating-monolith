<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class RouteSettings
{
    /** @var list<Destination> */
    private array $destinations;

    public function __construct(public Location $base, Destination ...$destinations)
    {
        foreach ($destinations as $destination) {
            if ($base->equals($destination->location)) {
                throw new AppException('Направление не может совпадать с базовой точкой.');
            }
        }
        $this->destinations = $destinations;
    }

    /** @return list<Destination> */
    public function destinations(): array
    {
        return $this->destinations;
    }
}
