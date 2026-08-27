<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateSurfaceTreatment;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateSurfaceTreatmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SurfaceTreatmentRepositoryInterface $repo,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(UpdateSurfaceTreatmentCommand $cmd): UpdateSurfaceTreatmentCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $treatment = $this->repo->findById(Uuid::fromString($cmd->id));

        if (null === $treatment) {
            throw new AppException(sprintf('Подготовка поверхности с id %s не найдена.', $cmd->id), 404);
        }

        $treatment->setDescription($cmd->description);
        $treatment->setCode($cmd->code);
        $treatment->setStandardCode($cmd->standardCode);
        $treatment->setSubstrateScope($cmd->substrateScope);

        $this->repo->save($treatment);

        return new UpdateSurfaceTreatmentCommandResult();
    }
}
