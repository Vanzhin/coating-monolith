<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Reports\Domain\Aggregate\Report\Reference;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Резолв ссылок отчёта из справочников/CoatingSystem в снимки {id,title} + засев слоёв системы.
 * Общий для создания и редактирования реквизитов, чтобы правило «id→снимок» жило в одном месте.
 */
final readonly class ReportReferenceResolver
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private CounterpartyRepositoryInterface $counterparties,
        private CoatingSystemRepositoryInterface $systems,
    ) {
    }

    public function resolveProject(?string $id): ?Reference
    {
        if (null === $id) {
            return null;
        }
        $project = $this->projects->findOneById($id);
        if (null === $project) {
            throw new AppException('Проект не найден.', Response::HTTP_NOT_FOUND);
        }

        return new Reference($project->getId(), $project->getTitle());
    }

    public function resolveCounterparty(?string $id): ?Reference
    {
        if (null === $id) {
            return null;
        }
        $counterparty = $this->counterparties->findOneById($id);
        if (null === $counterparty) {
            throw new AppException('Контрагент не найден.', Response::HTTP_NOT_FOUND);
        }

        return new Reference($counterparty->getId(), $counterparty->getTitle());
    }

    /** Система покрытия обязательна для отчёта: без неё нечего засевать и не выбрать шаблон акта. */
    public function findSystem(?string $id): CoatingSystem
    {
        if (null === $id) {
            throw new AppException('Выберите систему покрытия — она обязательна для отчёта.');
        }
        $system = Uuid::isValid($id) ? $this->systems->findById(Uuid::fromString($id)) : null;
        if (null === $system) {
            throw new AppException('Система покрытия не найдена.', Response::HTTP_NOT_FOUND);
        }

        return $system;
    }

    public function systemReference(CoatingSystem $system): Reference
    {
        return new Reference($system->getId(), $system->getTitle());
    }

    /**
     * Снимок слоёв системы (план): материал-ссылка + номинальная ТСП + цвет + порядок.
     *
     * @return list<array<string, mixed>>
     */
    public function seedSystemLayers(CoatingSystem $system): array
    {
        $layers = [];
        foreach ($system->getLayers() as $layer) {
            $coating = $layer->getCoating();
            $layers[] = [
                'material' => ['id' => $coating->getId(), 'title' => $coating->getTitle()],
                'dft_nominal' => $layer->getDft(),
                'color' => $layer->getColor()->label(),
                'order' => $layer->getPosition(),
            ];
        }

        return $layers;
    }
}
