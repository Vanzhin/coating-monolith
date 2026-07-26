<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\ListCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class ListCoatingSystemsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repository,
        private CoatingSystemDTOTransformer $transformer,
    ) {
    }

    /**
     * @return array{items: list<CoatingSystemDTO>, total: int}
     */
    public function __invoke(ListCoatingSystemsQuery $query): array
    {
        $offset = ($query->page - 1) * $query->perPage;

        $systems = $this->repository->list($query->filter, $query->perPage, $offset);
        $total = $this->repository->count($query->filter);

        return [
            'items' => array_map([$this->transformer, 'fromEntity'], $systems),
            'total' => $total,
        ];
    }
}
