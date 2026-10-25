<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTOTransformer;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Service\SystemCertificatesGateway;
use App\Coatings\Infrastructure\Cache\CoatingSystemComplianceCacheRepository;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class SearchCoatingSystemsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemComplianceCacheRepository $complianceCache,
        private CoatingSystemDTOTransformer $transformer,
        private SystemCertificatesGateway $certificates,
    ) {
    }

    public function __invoke(SearchCoatingSystemsQuery $q): SearchCoatingSystemsQueryResult
    {
        $page = $this->repo->findByFilter($q->filter);
        $systems = $page->items;
        $ids = new StringCollection(...array_map(static fn ($s): string => (string) $s->getId(), $systems));
        $complianceBySystem = $this->complianceCache->findBySystemIds($ids->getList());
        $documentCounts = $this->certificates->countBySystemIds($ids);
        $downloadUrls = $this->certificates->downloadUrlsBySystemIds($ids);
        $items = array_map(
            fn ($s) => $this->transformer->fromEntity(
                $s,
                $complianceBySystem[$s->getId()] ?? [],
                $documentCounts[$s->getId()] ?? 0,
                $downloadUrls[$s->getId()] ?? null,
            ),
            $systems,
        );

        return new SearchCoatingSystemsQueryResult($items, $page->total);
    }
}
