<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetDocument;

use App\Certificates\Application\DTO\Documents\DocumentDTOTransformer;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetDocumentQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private IssuerRepositoryInterface $issuers,
        private DocumentDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetDocumentQuery $query): GetDocumentQueryResult
    {
        $document = $this->documents->findOneById($query->id);
        if (null === $document) {
            return new GetDocumentQueryResult(null);
        }

        $issuerTitle = $this->issuers->findOneById($document->getIssuerId())?->getTitle();

        return new GetDocumentQueryResult($this->transformer->fromEntity($document, $issuerTitle));
    }
}
