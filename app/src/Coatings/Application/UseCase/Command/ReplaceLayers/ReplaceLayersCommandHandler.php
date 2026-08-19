<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\ReplaceLayers;

use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class ReplaceLayersCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingRepositoryInterface $coatingRepo,
        private ColorRepositoryInterface $colorRepo,
    ) {
    }

    public function __invoke(ReplaceLayersCommand $cmd): void
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));
        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $prepared = [];
        foreach ($cmd->items as $item) {
            $coating = $this->coatingRepo->findOneById($item['coatingId']);
            if (null === $coating) {
                throw new AppException(sprintf('Покрытие с id %s не найдено.', $item['coatingId']), 404);
            }
            $prepared[] = ['coating' => $coating, 'dft' => $item['dft'], 'color' => $this->resolveColor($item['colorId'] ?? null)];
        }

        $system->replaceLayers($prepared);
        $this->repo->save($system);
    }

    private function resolveColor(?string $colorId): ?Color
    {
        if (null === $colorId || '' === $colorId) {
            return null;
        }

        $color = $this->colorRepo->findOneById($colorId);
        if (null === $color) {
            throw new AppException(sprintf('Цвет с id %s не найден.', $colorId), 404);
        }

        return $color;
    }
}
