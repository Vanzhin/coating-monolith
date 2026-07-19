<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\CreateSubstance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

final class CreateSubstanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SubstanceRepositoryInterface $repo,
        private SubstanceSpecification $specification,
    ) {
    }

    public function __invoke(CreateSubstanceCommand $c): string
    {
        $cas = null !== $c->cas ? CasNumber::fromString($c->cas) : null;
        $sub = new Substance(
            Uuid::v4(),
            $c->canonicalName,
            $cas,
            new StringCollection(...$c->aliases),
            $this->specification,
        );
        $this->repo->add($sub);

        return $sub->getId();
    }
}
