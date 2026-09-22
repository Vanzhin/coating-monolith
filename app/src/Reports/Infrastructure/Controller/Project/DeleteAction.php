<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Project;

use App\Reports\Application\UseCase\Command\DeleteProject\DeleteProjectCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/reports/project/{id}/delete',
    name: 'app_cabinet_reports_project_delete',
    methods: ['POST'],
)]
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
            $this->commandBus->execute(new DeleteProjectCommand($id));
            $this->addFlash('project_removed_success', 'Проект удалён.');
        } catch (AppException $e) {
            $this->addFlash('project_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_reports_project_list');
    }
}
