<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateCoatingSystem;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateCoatingSystemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingRepositoryInterface $coatingRepo,
        private SurfaceTreatmentRepositoryInterface $surfaceTreatmentRepo,
        private TagRepositoryInterface $tagRepo,
        private ColorRepositoryInterface $colorRepo,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(CreateCoatingSystemCommand $cmd): CreateCoatingSystemCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

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

            $colorId = $layerData['colorId'] ?? null;
            if (null === $colorId || '' === $colorId) {
                throw new AppException(sprintf('Для слоя с покрытием «%s» не указан цвет.', $coating->getTitle()));
            }
            $color = $this->colorRepo->findOneById($colorId);
            if (null === $color) {
                throw new AppException(sprintf('Цвет с id %s не найден.', $colorId), 404);
            }

            $system->appendLayer($coating, $layerData['dft'], $color);
        }

        if ($cmd->tagIds->count() > 0) {
            $tags = $this->tagRepo->findByIds($cmd->tagIds);
            $system->replaceTags($tags);
        }

        $this->repo->save($system);

        return new CreateCoatingSystemCommandResult($system->getId());
    }
}
