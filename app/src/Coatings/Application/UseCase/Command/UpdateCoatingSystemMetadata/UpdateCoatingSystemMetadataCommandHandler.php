<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata;

use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateCoatingSystemMetadataCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private SystemLockGuard $lockGuard,
        private SurfaceTreatmentRepositoryInterface $surfaceTreatmentRepo,
        private TagRepositoryInterface $tagRepo,
    ) {
    }

    public function __invoke(UpdateCoatingSystemMetadataCommand $cmd): UpdateCoatingSystemMetadataCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->id));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->id), 404);
        }

        $this->lockGuard->assertModifiable($cmd->id);

        $treatment = $this->surfaceTreatmentRepo->findById(Uuid::fromString($cmd->surfaceTreatmentId));
        if (null === $treatment) {
            throw new AppException(sprintf('Подготовка поверхности с id %s не найдена.', $cmd->surfaceTreatmentId));
        }

        $system->setTitle($cmd->title);
        $system->setDescription($cmd->description);
        $system->setSubstrateAndTreatment($cmd->substrate, $treatment);
        $system->setEnvironment($cmd->environment);

        $tags = $this->tagRepo->findByIds($cmd->tagIds);
        $system->replaceTags($tags);

        $this->repo->save($system);

        return new UpdateCoatingSystemMetadataCommandResult($system->getId());
    }
}
