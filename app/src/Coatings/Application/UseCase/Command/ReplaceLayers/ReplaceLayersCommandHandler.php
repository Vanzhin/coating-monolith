<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\ReplaceLayers;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Coatings\Domain\Service\SystemLockGuard;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

final readonly class ReplaceLayersCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private SystemLockGuard $lockGuard,
        private CoatingRepositoryInterface $coatingRepo,
        private ColorRepositoryInterface $colorRepo,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(ReplaceLayersCommand $cmd): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));
        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $this->lockGuard->assertModifiable($cmd->systemId);

        $prepared = [];
        foreach ($cmd->items as $item) {
            $coating = $this->coatingRepo->findOneById($item['coatingId']);
            if (null === $coating) {
                throw new AppException(sprintf('Покрытие с id %s не найдено.', $item['coatingId']), 404);
            }
            $prepared[] = ['coating' => $coating, 'dft' => $item['dft'], 'color' => $this->resolveColor($item['colorId'])];
        }

        $system->replaceLayers($prepared);
        $this->repo->save($system);
    }

    private function resolveColor(?string $colorId): Color
    {
        if (null === $colorId || '' === $colorId) {
            throw new AppException('Для слоя не указан цвет.');
        }

        $color = $this->colorRepo->findOneById($colorId);
        if (null === $color) {
            throw new AppException(sprintf('Цвет с id %s не найден.', $colorId), 404);
        }

        return $color;
    }
}
