<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Coatings\Infrastructure\Search\CoatingSystemFinder;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SearchCoatingSystemsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemFinder $finder,
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private CoatingSystemDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SearchCoatingSystemsQuery $q): SearchCoatingSystemsQueryResult
    {
        $searchResult = $this->finder->find($q->filter);
        $systems = $this->repo->findByIds($searchResult->ids);
        $complianceBySystem = $this->complianceCache->findBySystemIds($searchResult->ids->getList());
        $items = array_map(
            fn ($s) => $this->transformer->fromEntity($s, $complianceBySystem[$s->getId()] ?? []),
            $systems,
        );

        return new SearchCoatingSystemsQueryResult($items, $searchResult->total);
    }
}
