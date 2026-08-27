<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateSurfaceTreatmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SurfaceTreatmentRepositoryInterface $repo,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(CreateSurfaceTreatmentCommand $cmd): CreateSurfaceTreatmentCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $treatment = new SurfaceTreatment(
            Uuid::v7(),
            $cmd->description,
            $cmd->code,
            $cmd->standardCode,
            $cmd->substrateScope,
        );

        $this->repo->save($treatment);

        return new CreateSurfaceTreatmentCommandResult($treatment->getId());
    }
}
