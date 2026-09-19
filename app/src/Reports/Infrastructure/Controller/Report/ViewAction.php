<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Query\GetReport\GetReportQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Просмотр отчёта. Доступ (владелец/админ) проверяет GetReport-хендлер; чужой отчёт → отказ.
 */
#[Route(path: '/cabinet/report/{id}', name: 'app_cabinet_report_view', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class ViewAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $result = $this->queryBus->execute(new GetReportQuery($id));
        if (null === $result->report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        return $this->render('cabinet/report/view.html.twig', ['report' => $result->report]);
    }
}
