<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\DeleteDepartment\DeleteDepartmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Триггер — общая components/delete_modal.html.twig: URL строится с companyId в query
 * (data-bs-url), чтобы вернуться на дерево той же компании без похода в репозиторий за
 * уже удалённым отделом.
 */
#[Route(
    path: '/cabinet/personnel/department/{id}/delete',
    name: 'app_cabinet_personnel_department_delete',
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
        $companyId = (string) $request->query->get('companyId', '');

        try {
            $this->commandBus->execute(new DeleteDepartmentCommand($id));
            $this->addFlash('department_removed_success', 'Отдел удалён.');
        } catch (AppException $e) {
            $this->addFlash('department_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_personnel_department_list', array_filter(['companyId' => $companyId]));
    }
}
