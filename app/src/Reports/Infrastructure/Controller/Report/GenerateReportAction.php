<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Query\GetReportRenderData\GetReportRenderDataQuery;
use App\Reports\Application\UseCase\Query\GetReportRenderData\GetReportRenderDataQueryResult;
use App\Reports\Infrastructure\Service\ReportReadinessChecker;
use App\Reports\Infrastructure\Service\ReportTemplateLocator;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\TemplateRendering;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Генерация и скачивание файла отчёта: данные (RenderData) собирает Application по маппингу,
 * шаблон выбирается по (тип, число слоёв), рендер — движок шаблонов. Доступ — в query-хендлере.
 */
#[Route(path: '/cabinet/report/{id}/download', name: 'app_cabinet_report_download', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class GenerateReportAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ReportTemplateLocator $locator,
        private readonly TemplateRendering $rendering,
        private readonly ReportReadinessChecker $readiness,
    ) {
    }

    public function __invoke(string $id): Response
    {
        try {
            $result = $this->queryBus->execute(new GetReportRenderDataQuery($id));
            \assert($result instanceof GetReportRenderDataQueryResult);

            // Проверка перед формированием: чего не хватает по обязательным полям шаблона акта.
            $missing = $this->readiness->missing($result);
            if ([] !== $missing) {
                $this->addFlash('warning', 'Нельзя сформировать акт — не заполнено: '.implode(', ', $missing).'.');

                return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $id]);
            }

            $template = $this->locator->locate($result->type, $result->layerCount);
            $doc = $this->rendering->render($template, $result->data);
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $id]);
        }

        $name = 'Акт-'.($result->actNumber ?: $id).'.'.$doc->extension();
        $response = new Response($doc->content, Response::HTTP_OK, ['Content-Type' => $doc->mimeType()]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            'report.'.$doc->extension(),
        ));

        return $response;
    }
}
