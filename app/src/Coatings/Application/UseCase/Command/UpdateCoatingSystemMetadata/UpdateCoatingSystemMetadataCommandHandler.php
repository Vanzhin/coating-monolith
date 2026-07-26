<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateCoatingSystemMetadataCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingSystemChainValidator $chainValidator,
    ) {
    }

    public function __invoke(UpdateCoatingSystemMetadataCommand $cmd): UpdateCoatingSystemMetadataCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->id));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->id), 404);
        }

        $system->setChainValidator($this->chainValidator);
        $system->setTitle($cmd->title);
        $system->setDescription($cmd->description);
        $system->setSubstrate($cmd->substrate);
        $system->setSurfacePreparation($cmd->surfacePreparation);

        $this->repo->save($system);

        return new UpdateCoatingSystemMetadataCommandResult($system->getId());
    }
}
