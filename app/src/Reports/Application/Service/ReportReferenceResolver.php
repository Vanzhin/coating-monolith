<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\UseCase\Query\FindCoatingSystemById\FindCoatingSystemByIdQuery;
use App\Reports\Domain\Aggregate\Report\Reference;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Резолв ссылок отчёта из справочников/CoatingSystem в снимки {id,title} + засев слоёв системы.
 * Общий для создания и редактирования реквизитов, чтобы правило «id→снимок» жило в одном месте.
 *
 * Систему тянем через опубликованный query Coatings (DTO), а не через репозиторий/агрегат чужого домена:
 * Reports зависит только от Coatings\Application (граница контекстов), домен Coatings сюда не протекает.
 */
final readonly class ReportReferenceResolver
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private CounterpartyRepositoryInterface $counterparties,
        private QueryBusInterface $queryBus,
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
    public function findSystem(?string $id): CoatingSystemDTO
    {
        if (null === $id) {
            throw new AppException('Выберите систему покрытия — она обязательна для отчёта.');
        }
        // Uuid::isValid — до query: её хендлер зовёт Uuid::fromString без проверки, невалидный id иначе
        // кинул бы \InvalidArgumentException вместо «не найдена».
        $system = Uuid::isValid($id) ? $this->queryBus->execute(new FindCoatingSystemByIdQuery($id)) : null;
        if (!$system instanceof CoatingSystemDTO) {
            throw new AppException('Система покрытия не найдена.', Response::HTTP_NOT_FOUND);
        }

        return $system;
    }

    public function systemReference(CoatingSystemDTO $system): Reference
    {
        return new Reference($system->id, $system->title);
    }

    /**
     * Снимок слоёв системы (план): материал-ссылка + номинальная ТСП + цвет + порядок.
     *
     * @return list<array<string, mixed>>
     */
    public function seedSystemLayers(CoatingSystemDTO $system): array
    {
        $layers = [];
        foreach ($system->layers as $layer) {
            $layers[] = [
                'material' => ['id' => $layer->coatingId, 'title' => $layer->coatingTitle],
                'dft_nominal' => $layer->dft,
                'color' => $layer->colorLabel,
                'order' => $layer->position,
            ];
        }

        return $layers;
    }
}
