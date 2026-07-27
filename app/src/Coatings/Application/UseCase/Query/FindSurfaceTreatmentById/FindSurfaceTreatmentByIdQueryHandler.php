<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById;

use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTO;
use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTOTransformer;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use Symfony\Component\Uid\Uuid;

readonly class FindSurfaceTreatmentByIdQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SurfaceTreatmentRepositoryInterface $repository,
        private SurfaceTreatmentDTOTransformer $transformer,
    ) {
    }

    public function __invoke(FindSurfaceTreatmentByIdQuery $query): ?SurfaceTreatmentDTO
    {
        $id = Uuid::fromString($query->id);
        $treatment = $this->repository->findById($id);

        if (null === $treatment) {
            return null;
        }

        return $this->transformer->fromEntity($treatment);
    }
}
