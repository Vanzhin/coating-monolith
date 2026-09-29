<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\UpdateDepartment\UpdateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\UpdateDepartment\UpdateDepartmentCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Нет отдельной GET-страницы формы: переименование — модалка на дереве отделов (ListAction).
 * POST всегда возвращается на дерево (companyId — скрытое поле формы модалки, эхо страницы).
 */
#[Route(
    path: '/cabinet/personnel/department/{id}/edit',
    name: 'app_cabinet_personnel_department_update',
    methods: ['POST'],
)]
final class UpdateAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $inputData = $request->getPayload()->all();
        $companyId = (string) ($inputData['companyId'] ?? '');

        try {
            $result = $this->commandBus->execute(new UpdateDepartmentCommand($id, (string) ($inputData['title'] ?? '')));
            \assert($result instanceof UpdateDepartmentCommandResult);
            $this->addFlash('department_updated_success', sprintf('Отдел «%s» обновлён.', $result->title));
        } catch (AppException $e) {
            $this->addFlash('department_updated_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_personnel_department_list', array_filter(['companyId' => $companyId]));
    }
}
