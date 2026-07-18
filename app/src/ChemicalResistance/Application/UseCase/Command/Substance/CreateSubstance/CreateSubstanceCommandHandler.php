<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueCasSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueSubstanceNameSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

final class CreateSubstanceCommandHandler
{
    public function __construct(private SubstanceRepository $repo) {}

    public function __invoke(CreateSubstanceCommand $c): string
    {
        $cas = $c->cas !== null ? CasNumber::fromString($c->cas) : null;
        $sub = new Substance(
            Uuid::v4(),
            $c->canonicalName,
            $cas,
            new StringCollection(...$c->aliases),
            $this->makeSpec(),
        );
        $this->repo->save($sub);
        return $sub->getId();
    }

    private function makeSpec(): SubstanceSpecification
    {
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($this->repo),
            new UniqueCasSpecification($this->repo),
        );
    }
}
