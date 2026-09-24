<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Command\RejectReport\RejectReportCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Отклонение отчёта ревьюером/админом с причиной для автора. Права — в хендлере (canReview).
 */
#[Route(path: '/cabinet/report/{id}/reject', name: 'app_cabinet_report_reject', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class RejectAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $reason = trim((string) $request->getPayload()->get('reason')) ?: null;
        try {
            $this->commandBus->execute(new RejectReportCommand($id, $reason));
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $id]);
    }
}
