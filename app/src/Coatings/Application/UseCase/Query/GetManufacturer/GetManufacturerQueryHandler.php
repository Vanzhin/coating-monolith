<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetManufacturer;

use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTOTransformer;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetManufacturerQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private ManufacturerDTOTransformer      $manufacturerDTOTransformer
    )
    {
    }

    public function __invoke(GetManufacturerQuery $query): GetManufacturerQueryResult
    {
        $manufacturer = $this->manufacturerRepository->findOneById($query->manufacturerId);
        if (null === $manufacturer) {
            return new GetManufacturerQueryResult(null);
        }

        return new GetManufacturerQueryResult($this->manufacturerDTOTransformer->fromEntity($manufacturer));
    }
}
