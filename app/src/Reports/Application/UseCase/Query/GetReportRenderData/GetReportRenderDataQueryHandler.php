<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetReportRenderData;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Application\Service\ReportRenderDataProjector;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Готовит данные для генерации файла: проекция отчёта в RenderData + тип и число слоёв (по нему
 * потребитель выбирает шаблон). Доступ — владелец/админ. Рендер (Infrastructure) делает контроллер.
 */
final readonly class GetReportRenderDataQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ReportRenderDataProjector $projector,
    ) {
    }

    public function __invoke(GetReportRenderDataQuery $query): GetReportRenderDataQueryResult
    {
        $report = $this->repository->findOneById($query->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEdit($report)) {
            throw new ForbiddenException();
        }

        $type = $report->getType();
        if (null === $type) {
            throw new AppException('У отчёта не задан вид документа — нечего формировать.');
        }

        $content = $report->getContent();
        $layers = $content['application']['layers'] ?? null;
        $layerCount = is_array($layers) ? \count($layers) : 0;

        return new GetReportRenderDataQueryResult(
            $this->projector->project($report),
            $type,
            $layerCount,
            $report->getActNumber(),
        );
    }
}
