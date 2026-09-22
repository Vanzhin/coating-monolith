<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Создание отчёта из модалки на списке: вид документа + система (обязательна, засевает план).
 * Реквизиты и блоки заполняются дальше на странице заполнения. Тонкий контроллер: ловит \Exception,
 * возвращает на список с сообщением.
 */
#[Route(path: '/cabinet/report/new', name: 'app_cabinet_report_create', methods: ['GET', 'POST'])]
final class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('app_cabinet_report_list');
        }

        $data = $request->getPayload();
        try {
            $result = $this->commandBus->execute(new CreateReportCommand(
                type: ReportType::tryFrom((string) $data->get('type')),
                systemId: $this->nullify((string) $data->get('systemId')),
            ));
            \assert($result instanceof CreateReportCommandResult);

            return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $result->id]);
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_cabinet_report_list');
        }
    }

    private function nullify(string $value): ?string
    {
        return '' !== trim($value) ? trim($value) : null;
    }
}
