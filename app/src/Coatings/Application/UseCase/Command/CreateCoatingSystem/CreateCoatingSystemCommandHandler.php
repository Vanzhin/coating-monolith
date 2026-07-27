<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidatorInterface;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateCoatingSystemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingRepositoryInterface $coatingRepo,
        private CoatingSystemChainValidatorInterface $chainValidator,
    ) {
    }

    public function __invoke(CreateCoatingSystemCommand $cmd): CreateCoatingSystemCommandResult
    {
        $system = new CoatingSystem(
            Uuid::v7(),
            $cmd->title,
            $cmd->description,
            $cmd->substrate,
            $cmd->surfacePreparation,
            $this->chainValidator,
        );

        foreach ($cmd->initialLayers as $layerData) {
            $coating = $this->coatingRepo->findOneById($layerData['coatingId']);
            if (null === $coating) {
                throw new AppException(sprintf('Покрытие с id %s не найдено.', $layerData['coatingId']));
            }
            $system->appendLayer($coating, $layerData['dft']);
        }

        $this->repo->save($system);

        return new CreateCoatingSystemCommandResult($system->getId());
    }
}
