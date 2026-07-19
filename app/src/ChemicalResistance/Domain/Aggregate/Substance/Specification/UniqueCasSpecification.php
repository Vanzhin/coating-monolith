<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Domain\Aggregate\Substance\Specification;

use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;

final class UniqueCasSpecification
{
    public function __construct(private SubstanceRepositoryInterface $repo)
    {
    }

    public function satisfy(Substance $s): void
    {
        if (null === $s->getCas()) {
            return;
        }
        $existing = $this->repo->findByCas($s->getCas());
        if (null !== $existing && $existing->getId() !== $s->getId()) {
            throw new AppException(sprintf('CAS-номер «%s» уже используется другим веществом в справочнике.', $s->getCas()));
        }
    }
}
