<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveCoatingSystem;

use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class RemoveCoatingSystemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
    ) {
    }

    public function __invoke(RemoveCoatingSystemCommand $cmd): RemoveCoatingSystemCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->id));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->id), 404);
        }

        $this->repo->remove($system);

        return new RemoveCoatingSystemCommandResult();
    }
}
