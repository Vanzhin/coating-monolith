<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree\GetCompanyDepartmentTreeQuery;
use App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree\GetCompanyDepartmentTreeQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Дерево отделов выбранной компании. Просмотр открыт всем авторизованным (PersonnelAccessControl:
 * read не гейтится). Компания выбирается typeahead'ом (companyId в query — shareable-ссылка,
 * название чипа гидрируется на клиенте через Reports\Counterparty\ByIdsAction).
 * Кнопки add/edit/move/delete/assign-head — в шаблоне под canEdit.
 */
#[Route(
    path: '/cabinet/personnel/department',
    name: 'app_cabinet_personnel_department_list',
    methods: ['GET'],
)]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = trim((string) $request->query->get('companyId', ''));
        $companyId = ('' !== $companyId && Uuid::isValid($companyId)) ? $companyId : null;

        $roots = [];
        $parentOptions = [];
        if (null !== $companyId) {
            $result = $this->queryBus->execute(new GetCompanyDepartmentTreeQuery($companyId));
            \assert($result instanceof GetCompanyDepartmentTreeQueryResult);
            $roots = $result->roots;
            $parentOptions = DepartmentTreeOptions::flatten($roots);
        }

        return $this->render('admin/personnel/department/index.html.twig', [
            'companyId' => $companyId,
            'roots' => $roots,
            'parentOptions' => $parentOptions,
        ]);
    }
}
