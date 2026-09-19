<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateReport;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Aggregate\Report\Reference;
use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Заводит отчёт: владелец — текущий актор (owner-based). Ссылки (проект/заказчик/подрядчик/система)
 * резолвятся из справочников/CoatingSystem в снимки {id,title}; несуществующий id — 404.
 */
final readonly class CreateReportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ProjectRepositoryInterface $projects,
        private CounterpartyRepositoryInterface $counterparties,
        private CoatingSystemRepositoryInterface $systems,
    ) {
    }

    public function __invoke(CreateReportCommand $command): CreateReportCommandResult
    {
        $now = new \DateTimeImmutable();
        $report = new Report(
            Uuid::v7(),
            $this->access->currentUserId(),
            $command->type,
            $now,
            $command->reportDate,
            $command->actNumber,
        );
        $system = $this->findSystem($command->systemId);
        $report->applyReferences(
            $this->resolveProject($command->projectId),
            $this->resolveCounterparty($command->customerId),
            $this->resolveCounterparty($command->contractorId),
            null === $system ? null : new Reference($system->getId(), $system->getTitle()),
            $now,
        );
        if (null !== $system) {
            // Засев плана: слои выбранной системы замораживаются в блок «Система (план)».
            $report->replaceContent(['system' => ['layers' => $this->seedSystemLayers($system)]], $now);
        }
        $this->repository->add($report);

        return new CreateReportCommandResult($report->getId());
    }

    private function resolveProject(?string $id): ?Reference
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

    private function resolveCounterparty(?string $id): ?Reference
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

    private function findSystem(?string $id): ?CoatingSystem
    {
        if (null === $id) {
            return null;
        }
        $system = Uuid::isValid($id) ? $this->systems->findById(Uuid::fromString($id)) : null;
        if (null === $system) {
            throw new AppException('Система покрытия не найдена.', Response::HTTP_NOT_FOUND);
        }

        return $system;
    }

    /**
     * Снимок слоёв системы (план): материал-ссылка + номинальная ТСП + цвет + порядок.
     *
     * @return list<array<string, mixed>>
     */
    private function seedSystemLayers(CoatingSystem $system): array
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
