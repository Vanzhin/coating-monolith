<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment\Specification;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;

final class UniqueCoatingSubstanceAssessmentSpecification
{
    public function __construct(private AssessmentRepositoryInterface $repo) {}

    public function satisfy(Assessment $a): void
    {
        $existing = $this->repo->findByCoatingAndSubstance($a->getCoatingId(), $a->getSubstanceId());
        if ($existing !== null && $existing->getId() !== $a->getId()) {
            throw new AppException('Оценка для этой пары «покрытие — вещество» уже существует.');
        }
    }
}
