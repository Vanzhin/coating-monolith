<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateCoatingSystemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingRepositoryInterface $coatingRepo,
        private SurfaceTreatmentRepositoryInterface $surfaceTreatmentRepo,
        private TagRepositoryInterface $tagRepo,
        private ColorRepositoryInterface $colorRepo,
    ) {
    }

    public function __invoke(CreateCoatingSystemCommand $cmd): CreateCoatingSystemCommandResult
    {
        $treatment = $this->surfaceTreatmentRepo->findById(Uuid::fromString($cmd->surfaceTreatmentId));
        if (null === $treatment) {
            throw new AppException(sprintf('Подготовка поверхности с id %s не найдена.', $cmd->surfaceTreatmentId));
        }

        $system = new CoatingSystem(
            Uuid::v7(),
            $cmd->title,
            $cmd->description,
            $cmd->substrate,
            $treatment,
            $cmd->environment,
        );

        foreach ($cmd->initialLayers as $layerData) {
            $coating = $this->coatingRepo->findOneById($layerData['coatingId']);
            if (null === $coating) {
                throw new AppException(sprintf('Покрытие с id %s не найдено.', $layerData['coatingId']));
            }

            $color = null;
            $colorId = $layerData['colorId'] ?? null;
            if (null !== $colorId && '' !== $colorId) {
                $color = $this->colorRepo->findOneById($colorId);
                if (null === $color) {
                    throw new AppException(sprintf('Цвет с id %s не найден.', $colorId), 404);
                }
            }

            $system->appendLayer($coating, $layerData['dft'], $color);
        }

        if ([] !== $cmd->tagIds) {
            $tags = $this->tagRepo->findByIds(new StringCollection(...$cmd->tagIds));
            $system->replaceTags($tags);
        }

        $this->repo->save($system);

        return new CreateCoatingSystemCommandResult($system->getId());
    }
}
