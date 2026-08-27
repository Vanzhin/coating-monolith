<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Command\Substance\DeleteSubstance;

use App\ChemicalResistance\Application\Service\AccessControl\ChemicalResistanceAccessControl;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final class DeleteSubstanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SubstanceRepositoryInterface $repo,
        private ChemicalResistanceAccessControl $access,
    ) {
    }

    public function __invoke(DeleteSubstanceCommand $c): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $sub = $this->repo->findOneById($c->id)
            ?? throw new AppException('Вещество не найдено.');
        try {
            $this->repo->remove($sub);
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            throw new AppException('Вещество используется в оценках химстойкости, удаление невозможно.');
        }
    }
}
