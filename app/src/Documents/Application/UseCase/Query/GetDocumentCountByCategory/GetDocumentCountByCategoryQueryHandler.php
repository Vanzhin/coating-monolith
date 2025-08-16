<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetDocumentCountByCategory;

use App\Documents\Application\DTO\Document\DocumentCountByCategoryDTOTransformer;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetDocumentCountByCategoryQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $repository,
        private DocumentCountByCategoryDTOTransformer $dtoTransformer
    ) {
    }

    public function __invoke(GetDocumentCountByCategoryQuery $query): GetDocumentCountByCategoryQueryResult
    {
        $result = $this->repository->findCountByCategory($query->filter);

        return new GetDocumentCountByCategoryQueryResult($this->dtoTransformer->fromArray($result));
    }
}