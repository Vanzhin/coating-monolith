<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystemsByCompliance;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class SearchCoatingSystemsByComplianceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repository,
        private CoatingSystemDTOTransformer $transformer,
    ) {
    }

    /**
     * @return array{items: list<CoatingSystemDTO>, total: int}
     */
    public function __invoke(SearchCoatingSystemsByComplianceQuery $query): array
    {
        $offset = ($query->page - 1) * $query->perPage;

        $systems = $this->repository->findByCompliance(
            $query->standard,
            $query->category,
            $query->durability,
            $query->substrate,
            $query->perPage,
            $offset,
        );
        $total = $this->repository->countByCompliance(
            $query->standard,
            $query->category,
            $query->durability,
            $query->substrate,
        );

        return [
            'items' => array_map([$this->transformer, 'fromEntity'], $systems),
            'total' => $total,
        ];
    }
}
