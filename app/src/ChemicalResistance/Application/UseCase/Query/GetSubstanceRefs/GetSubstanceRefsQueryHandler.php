<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstanceRefs;

use App\ChemicalResistance\Application\DTO\SubstanceRefDTO;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Резолв id → {id, canonicalName}. Порядок результата = порядок переданных id
 * (потерянные/несуществующие id просто выпадают).
 */
class GetSubstanceRefsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SubstanceRepositoryInterface $substances,
    ) {
    }

    /**
     * @return list<SubstanceRefDTO>
     */
    public function __invoke(GetSubstanceRefsQuery $query): array
    {
        $ids = $query->ids->getList();
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->substances->findAllByIds($ids) as $substance) {
            $byId[$substance->getId()] = new SubstanceRefDTO($substance->getId(), $substance->getCanonicalName());
        }

        // Сохраняем порядок запрошенных id.
        $refs = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $refs[] = $byId[$id];
            }
        }

        return $refs;
    }
}
