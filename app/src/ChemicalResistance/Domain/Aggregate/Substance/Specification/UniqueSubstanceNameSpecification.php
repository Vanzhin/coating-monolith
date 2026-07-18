<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance\Specification;

use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Infrastructure\Exception\AppException;

final class UniqueSubstanceNameSpecification
{
    public function __construct(private SubstanceRepository $repo) {}

    public function satisfy(Substance $s): void
    {
        $existing = $this->repo->findByCanonicalNameKey($s->getCanonicalNameKey());
        if ($existing !== null && $existing->getId() !== $s->getId()) {
            throw new AppException(sprintf(
                'Вещество «%s» уже существует в справочнике.',
                $s->getCanonicalName(),
            ));
        }
    }
}
