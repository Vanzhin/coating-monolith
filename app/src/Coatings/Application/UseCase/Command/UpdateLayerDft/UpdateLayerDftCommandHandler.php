<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateLayerDft;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateLayerDftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private SystemLockGuard $lockGuard,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(UpdateLayerDftCommand $cmd): UpdateLayerDftCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $this->lockGuard->assertModifiable($cmd->systemId);

        $system->updateLayerDft($cmd->position, $cmd->dft);
        $this->repo->save($system);

        return new UpdateLayerDftCommandResult();
    }
}
