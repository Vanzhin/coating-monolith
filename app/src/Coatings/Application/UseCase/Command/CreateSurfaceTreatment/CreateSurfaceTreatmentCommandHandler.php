<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment;

use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateSurfaceTreatmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SurfaceTreatmentRepositoryInterface $repo,
    ) {
    }

    public function __invoke(CreateSurfaceTreatmentCommand $cmd): CreateSurfaceTreatmentCommandResult
    {
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
