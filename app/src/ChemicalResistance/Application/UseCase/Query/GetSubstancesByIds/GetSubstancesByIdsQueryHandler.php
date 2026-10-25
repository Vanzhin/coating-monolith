<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstancesByIds;

use App\ChemicalResistance\Application\UseCase\Query\GetSubstanceRefs\GetSubstanceRefsQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetSubstanceRefs\GetSubstanceRefsQueryHandler;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetSubstancesByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private GetSubstanceRefsQueryHandler $substanceRefs,
    ) {
    }

    public function __invoke(GetSubstancesByIdsQuery $query): GetSubstancesByIdsQueryResult
    {
        return new GetSubstancesByIdsQueryResult(($this->substanceRefs)(new GetSubstanceRefsQuery($query->ids)));
    }
}
