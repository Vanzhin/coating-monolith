<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\ListSurfaceTreatments;

use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTO;
use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTOTransformer;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class ListSurfaceTreatmentsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SurfaceTreatmentRepositoryInterface $repository,
        private SurfaceTreatmentDTOTransformer $transformer,
    ) {
    }

    /**
     * @return array{items: list<SurfaceTreatmentDTO>, total: int}
     */
    public function __invoke(ListSurfaceTreatmentsQuery $query): array
    {
        $offset = ($query->page - 1) * $query->perPage;

        $treatments = $this->repository->list($query->filter, $query->perPage, $offset);
        $total = $this->repository->count($query->filter);

        $items = array_map(
            fn ($treatment) => $this->transformer->fromEntity($treatment),
            $treatments,
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
