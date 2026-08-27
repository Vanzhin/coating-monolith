<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\UpdateSubstance;

use App\ChemicalResistance\Application\Service\AccessControl\ChemicalResistanceAccessControl;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final class UpdateSubstanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SubstanceRepositoryInterface $repo,
        private ChemicalResistanceAccessControl $access,
    ) {
    }

    public function __invoke(UpdateSubstanceCommand $c): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $sub = $this->repo->findOneById($c->id)
            ?? throw new AppException('Вещество не найдено.');
        $sub->setCanonicalName($c->canonicalName);
        $sub->setCas(null !== $c->cas ? CasNumber::fromString($c->cas) : null);
        $sub->replaceAliases($c->aliases);
        $this->repo->add($sub);
    }
}
