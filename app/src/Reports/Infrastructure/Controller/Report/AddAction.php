<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Создание отчёта: вид документа + реквизиты/ссылки. Ссылки резолвятся и (для системы) засеваются
 * в CreateReport-хендлере. После создания — на просмотр. Тонкий контроллер: ловит \Exception,
 * перерисовывает форму с ошибкой.
 */
#[Route(path: '/cabinet/report/new', name: 'app_cabinet_report_create', methods: ['GET', 'POST'])]
final class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->getPayload();
            try {
                $result = $this->commandBus->execute(new CreateReportCommand(
                    type: ReportType::tryFrom((string) $data->get('type')),
                    reportDate: $this->parseDate((string) $data->get('reportDate')),
                    actNumber: $this->nullify((string) $data->get('actNumber')),
                    projectId: $this->nullify((string) $data->get('projectId')),
                    customerId: $this->nullify((string) $data->get('customerId')),
                    contractorId: $this->nullify((string) $data->get('contractorId')),
                    systemId: $this->nullify((string) $data->get('systemId')),
                ));
                \assert($result instanceof CreateReportCommandResult);

                return $this->redirectToRoute('app_cabinet_report_view', ['id' => $result->id]);
            } catch (\Exception $e) {
                return $this->render('cabinet/report/form.html.twig', [
                    'types' => ReportType::cases(),
                    'error' => $e->getMessage(),
                    'input' => $data->all(),
                ]);
            }
        }

        return $this->render('cabinet/report/form.html.twig', [
            'types' => ReportType::cases(),
            'input' => [],
        ]);
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
