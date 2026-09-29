<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\MoveDepartment\MoveDepartmentCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Нет отдельной GET-страницы формы: перенос — модалка на дереве отделов (ListAction), список
 * кандидатов в родители — общий на всю компанию (без исключения поддерева: только UX-подсказка
 * в JS модалки прячет сам узел, полную защиту от циклов даёт DepartmentTreePolicy в хендлере).
 * POST всегда возвращается на дерево (companyId — скрытое поле формы модалки).
 */
#[Route(
    path: '/cabinet/personnel/department/{id}/move',
    name: 'app_cabinet_personnel_department_move',
    methods: ['POST'],
)]
final class MoveAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $inputData = $request->getPayload()->all();
        $companyId = (string) ($inputData['companyId'] ?? '');
        $parentId = '' !== (string) ($inputData['parentId'] ?? '') ? (string) $inputData['parentId'] : null;

        try {
            $this->commandBus->execute(new MoveDepartmentCommand($id, $parentId));
            $this->addFlash('department_moved_success', 'Отдел перенесён.');
        } catch (AppException $e) {
            $this->addFlash('department_moved_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_personnel_department_list', array_filter(['companyId' => $companyId]));
    }
}
