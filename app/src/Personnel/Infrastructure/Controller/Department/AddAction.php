<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Нет отдельной GET-страницы формы: создание — модалка на дереве отделов (ListAction).
 * POST всегда возвращается на дерево (companyId — скрытое поле формы модалки, эхо страницы).
 */
#[Route(
    path: '/cabinet/personnel/department/create',
    name: 'app_cabinet_personnel_department_create',
    methods: ['POST'],
)]
final class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $inputData = $request->getPayload()->all();
        $companyId = (string) ($inputData['companyId'] ?? '');
        $parentId = '' !== (string) ($inputData['parentId'] ?? '') ? (string) $inputData['parentId'] : null;

        try {
            $result = $this->commandBus->execute(new CreateDepartmentCommand(
                $companyId,
                (string) ($inputData['title'] ?? ''),
                $parentId,
            ));
            \assert($result instanceof CreateDepartmentCommandResult);
            $this->addFlash('department_created_success', sprintf('Отдел «%s» добавлен.', $result->title));
        } catch (AppException $e) {
            $this->addFlash('department_created_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_personnel_department_list', array_filter(['companyId' => $companyId]));
    }
}
