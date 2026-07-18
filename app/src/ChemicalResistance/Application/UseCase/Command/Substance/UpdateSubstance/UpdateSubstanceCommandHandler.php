<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Command\Substance\UpdateSubstance;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final class UpdateSubstanceCommandHandler
{
    public function __construct(private SubstanceRepository $repo) {}

    public function __invoke(UpdateSubstanceCommand $c): void
    {
        $sub = $this->repo->find(Uuid::fromString($c->id))
            ?? throw new AppException('Вещество не найдено.');
        $sub->setCanonicalName($c->canonicalName);
        $sub->setCas($c->cas !== null ? CasNumber::fromString($c->cas) : null);
        $sub->replaceAliases($c->aliases);
        $this->repo->save($sub);
    }
}
