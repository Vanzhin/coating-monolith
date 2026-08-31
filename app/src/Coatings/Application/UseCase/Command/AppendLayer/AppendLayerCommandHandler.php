<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\AppendLayer;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class AppendLayerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private SystemLockGuard $lockGuard,
        private CoatingRepositoryInterface $coatingRepo,
        private ColorRepositoryInterface $colorRepo,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(AppendLayerCommand $cmd): AppendLayerCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $this->lockGuard->assertModifiable($cmd->systemId);

        $coating = $this->coatingRepo->findOneById($cmd->coatingId);

        if (null === $coating) {
            throw new AppException(sprintf('Покрытие с id %s не найдено.', $cmd->coatingId), 404);
        }

        $color = $this->colorRepo->findOneById($cmd->colorId);
        if (null === $color) {
            throw new AppException(sprintf('Цвет с id %s не найден.', $cmd->colorId), 404);
        }

        $layer = $system->appendLayer($coating, $cmd->dft, $color);
        $this->repo->save($system);

        return new AppendLayerCommandResult($layer->getId());
    }
}
