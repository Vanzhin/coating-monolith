<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstancesByIds;

use App\ChemicalResistance\Application\DTO\SubstanceRefDTO;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetSubstancesByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SubstanceRepositoryInterface $substanceRepository,
    ) {
    }

    public function __invoke(GetSubstancesByIdsQuery $query): GetSubstancesByIdsQueryResult
    {
        $substances = $this->substanceRepository->findAllByIds($query->ids->getList());
        $refs = array_map(
            fn ($substance) => new SubstanceRefDTO($substance->getId(), $substance->getCanonicalName()),
            $substances,
        );

        return new GetSubstancesByIdsQueryResult($refs);
    }
}
