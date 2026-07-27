<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveSurfaceTreatment;

use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class RemoveSurfaceTreatmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SurfaceTreatmentRepositoryInterface $repo,
        private CoatingSystemRepositoryInterface $coatingSystemRepo,
    ) {
    }

    public function __invoke(RemoveSurfaceTreatmentCommand $cmd): RemoveSurfaceTreatmentCommandResult
    {
        $treatment = $this->repo->findById(Uuid::fromString($cmd->id));

        if (null === $treatment) {
            throw new AppException(sprintf('Подготовка поверхности с id %s не найдена.', $cmd->id), 404);
        }

        $countUsing = $this->coatingSystemRepo->countUsingSurfaceTreatment($treatment->getId());

        if ($countUsing > 0) {
            $title = $treatment->getCode() ?? $treatment->getDescription();
            throw new AppException(sprintf(
                'Нельзя удалить подготовку поверхности «%s»: используется в %d системах покрытий.',
                $title,
                $countUsing,
            ));
        }

        $this->repo->remove($treatment);

        return new RemoveSurfaceTreatmentCommandResult();
    }
}
