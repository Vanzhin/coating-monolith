<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\SubstanceNamesByIds;

use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SubstanceNamesByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SubstanceRepositoryInterface $substances)
    {
    }

    /**
     * @return array<string, string> id → каноническое имя
     */
    public function __invoke(SubstanceNamesByIdsQuery $query): array
    {
        if ([] === $query->ids) {
            return [];
        }

        $map = [];
        foreach ($this->substances->findAllByIds($query->ids) as $substance) {
            $map[$substance->getId()] = $substance->getCanonicalName();
        }

        return $map;
    }
}
