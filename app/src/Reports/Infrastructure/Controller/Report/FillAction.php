<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\DTO\Reports\ReportDTO;
use App\Reports\Application\Service\ReportFormPresenter;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Application\UseCase\Command\SubmitForReview\SubmitForReviewCommand;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQuery;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQueryResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Заполнение отчёта: форма из схемы блоков его типа. Сохранение — черновик (лёгкая валидация,
 * авто-«в работу»); «На проверку» — строгая валидация. Доступ (владелец/админ) — в хендлерах.
 */
#[Route(path: '/cabinet/report/{id}/fill', name: 'app_cabinet_report_fill', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class FillAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ReportFormPresenter $presenter,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $raw = $request->getPayload()->all();
            $content = \is_array($raw['content'] ?? null) ? $raw['content'] : [];
            try {
                $this->commandBus->execute(new SaveReportContentCommand($id, $content));
                if ('submit' === $request->getPayload()->get('action')) {
                    $this->commandBus->execute(new SubmitForReviewCommand($id));
                }

                return $this->redirectToRoute('app_cabinet_report_view', ['id' => $id]);
            } catch (\Exception $e) {
                return $this->renderForm($id, $content, $e->getMessage());
            }
        }

        $report = $this->loadReport($id);

        return $this->renderForm($id, $report->content);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function renderForm(string $id, array $content, ?string $error = null): Response
    {
        $report = $this->loadReport($id);
        $type = null !== $report->typeKey ? ReportType::from($report->typeKey) : null;

        return $this->render('cabinet/report/fill.html.twig', [
            'report' => $report,
            'sections' => null !== $type ? $this->presenter->sections($type, $content) : [],
            'error' => $error,
        ]);
    }

    private function loadReport(string $id): ReportDTO
    {
        $result = $this->queryBus->execute(new GetReportQuery($id));
        \assert($result instanceof GetReportQueryResult);
        if (null === $result->report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        return $result->report;
    }
}
