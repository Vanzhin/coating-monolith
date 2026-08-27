<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateLayerDft;

use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateLayerDftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private SystemLockGuard $lockGuard,
    ) {
    }

    public function __invoke(UpdateLayerDftCommand $cmd): UpdateLayerDftCommandResult
    {
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
