<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\DTO\Reports\ReportDTO;
use App\Reports\Application\UseCase\Command\UpdateReportHeader\UpdateReportHeaderCommand;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQuery;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Правка реквизитов отчёта (дата/№/ссылки/система) — та же форма, что и создание, с предзаполнением.
 * Тип не меняется. Доступ/заморозку стережёт хендлер.
 */
#[Route(path: '/cabinet/report/{id}/edit', name: 'app_cabinet_report_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class EditAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->getPayload();
            try {
                $this->commandBus->execute(new UpdateReportHeaderCommand(
                    reportId: $id,
                    reportDate: $this->parseDate((string) $data->get('reportDate')),
                    actNumber: $this->nullify((string) $data->get('actNumber')),
                    projectId: $this->nullify((string) $data->get('projectId')),
                    customerId: $this->nullify((string) $data->get('customerId')),
                    contractorId: $this->nullify((string) $data->get('contractorId')),
                    systemId: $this->nullify((string) $data->get('systemId')),
                ));

                return $this->redirectToRoute('app_cabinet_report_view', ['id' => $id]);
            } catch (\Exception $e) {
                return $this->render('cabinet/report/form.html.twig', ['editing' => true, 'report' => $this->load($id), 'error' => $e->getMessage()]);
            }
        }

        return $this->render('cabinet/report/form.html.twig', ['editing' => true, 'report' => $this->load($id)]);
    }

    private function load(string $id): ReportDTO
    {
        $result = $this->queryBus->execute(new GetReportQuery($id));
        \assert($result instanceof GetReportQueryResult);
        if (null === $result->report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        return $result->report;
    }

    private function nullify(string $value): ?string
    {
        return '' !== trim($value) ? trim($value) : null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        return '' !== $value ? new \DateTimeImmutable($value) : null;
    }
}
