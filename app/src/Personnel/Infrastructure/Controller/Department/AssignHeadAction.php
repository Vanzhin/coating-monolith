<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\AssignDepartmentHead\AssignDepartmentHeadCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Нет отдельной GET-страницы формы: назначение начальника — модалка на дереве отделов
 * (ListAction), поле пустое при открытии (без прошлого значения — оно и так видно бейджем
 * в строке отдела). Пустой headUserUlid снимает начальника. POST всегда возвращается на дерево.
 */
#[Route(
    path: '/cabinet/personnel/department/{id}/assign-head',
    name: 'app_cabinet_personnel_department_assign_head',
    methods: ['POST'],
)]
final class AssignHeadAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $inputData = $request->getPayload()->all();
        $companyId = (string) ($inputData['companyId'] ?? '');
        $headUserUlid = '' !== (string) ($inputData['headUserUlid'] ?? '') ? (string) $inputData['headUserUlid'] : null;

        try {
            $this->commandBus->execute(new AssignDepartmentHeadCommand($id, $headUserUlid));
            $this->addFlash('department_head_assigned_success', 'Начальник отдела обновлён.');
        } catch (AppException $e) {
            $this->addFlash('department_head_assigned_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_personnel_department_list', array_filter(['companyId' => $companyId]));
    }
}
