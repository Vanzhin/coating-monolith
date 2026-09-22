<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Command\DeleteReport\DeleteReportCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Удаление отчёта. Доступ (владелец/админ) и заморозку (утверждённый нельзя) стережёт хендлер/домен.
 * Успех → к списку; отказ → назад на просмотр с сообщением.
 */
#[Route(path: '/cabinet/report/{id}/delete', name: 'app_cabinet_report_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class DeleteAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $this->csrfGuard->assertValid('delete', $request->request->getString('_token'));
        try {
            $this->commandBus->execute(new DeleteReportCommand($id));
            $this->addFlash('success', 'Отчёт удалён.');

            return $this->redirectToRoute('app_cabinet_report_list');
        } catch (AppException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $id]);
        }
    }
}
