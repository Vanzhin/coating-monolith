<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\MoveLayer;

use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class MoveLayerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private SystemLockGuard $lockGuard,
    ) {
    }

    public function __invoke(MoveLayerCommand $cmd): MoveLayerCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $this->lockGuard->assertModifiable($cmd->systemId);

        $system->moveLayer($cmd->from, $cmd->to);
        $this->repo->save($system);

        return new MoveLayerCommandResult();
    }
}
