<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateLayerDft;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateLayerDftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemChainValidator $chainValidator,
    ) {
    }

    public function __invoke(UpdateLayerDftCommand $cmd): UpdateLayerDftCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $system->setChainValidator($this->chainValidator);
        $system->updateLayerDft($cmd->position, $cmd->dft);
        $this->repo->save($system);

        return new UpdateLayerDftCommandResult();
    }
}
