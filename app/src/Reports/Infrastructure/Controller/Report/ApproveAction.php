<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Command\ApproveReport\ApproveReportCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Утверждение отчёта ревьюером/админом. Права проверяет хендлер (canReview). Тонкий контроллер:
 * ошибку кладёт во flash, редиректит обратно на просмотр.
 */
#[Route(path: '/cabinet/report/{id}/approve', name: 'app_cabinet_report_approve', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class ApproveAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->execute(new ApproveReportCommand($id));
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_report_view', ['id' => $id]);
    }
}
