<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance\Specification;

use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Infrastructure\Exception\AppException;

final class UniqueCasSpecification
{
    public function __construct(private SubstanceRepository $repo) {}

    public function satisfy(Substance $s): void
    {
        if ($s->getCas() === null) {
            return;
        }
        $existing = $this->repo->findByCas($s->getCas());
        if ($existing !== null && $existing->getId() !== $s->getId()) {
            throw new AppException(sprintf(
                'CAS-номер «%s» уже используется другим веществом в справочнике.',
                $s->getCas(),
            ));
        }
    }
}
