<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetPagedDocuments;

use App\Documents\Application\DTO\Document\DocumentDTOTransformer;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

readonly class GetPagedDocumentsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $repository,
        private DocumentDTOTransformer $dtoTransformer
    ) {
    }

    public function __invoke(GetPagedDocumentsQuery $query): GetPagedDocumentsQueryResult
    {
        $paginator = $this->repository->search($query->filter);
        $documents = $this->dtoTransformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedDocumentsQueryResult($documents, $pager);
    }
}