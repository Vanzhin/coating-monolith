<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetPagedDocuments;

use App\Certificates\Application\DTO\Documents\DocumentDTOTransformer;
use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedDocumentsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private IssuerRepositoryInterface $issuers,
        private DocumentDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPagedDocumentsQuery $query): GetPagedDocumentsQueryResult
    {
        $paginator = $this->documents->findByFilter($query->filter);

        /** @var list<Document> $items */
        $items = $paginator->items;
        $dtos = $this->transformer->fromEntityList($items, $this->issuerTitles($items));

        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new GetPagedDocumentsQueryResult($dtos, $pager);
    }

    /**
     * @param list<Document> $documents
     *
     * @return array<string, string> map issuerId → title
     */
    private function issuerTitles(array $documents): array
    {
        $ids = array_values(array_unique(array_map(static fn (Document $d) => $d->getIssuerId(), $documents)));
        if ([] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($this->issuers->findByIds(new StringCollection(...$ids)) as $issuer) {
            /* @var Issuer $issuer */
            $titles[$issuer->getId()] = $issuer->getTitle();
        }

        return $titles;
    }
}
