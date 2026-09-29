<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Query\SuggestDepartments\SuggestDepartmentsQuery;
use App\Personnel\Application\UseCase\Query\SuggestDepartments\SuggestDepartmentsQueryResult;
use App\Personnel\Domain\Repository\DepartmentsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Постраничный typeahead отделов (форма профиля). Поиск — через DepartmentsFilter (title + companyId-скоуп
 * для водопада организация → отдел). Отдаёт {items, page, hasMore} — общий typeahead догружает скроллом.
 * Read-запрос не гейтим — справочник открыт всем авторизованным.
 */
#[Route(
    path: '/cabinet/personnel/department/suggest',
    name: 'app_cabinet_personnel_department_suggest',
    methods: ['GET'],
)]
final class SuggestAction extends AbstractController
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));
        $companyId = trim((string) $request->query->get('companyId', ''));

        if ('' === $q) {
            return new JsonResponse(['items' => [], 'page' => 1, 'hasMore' => false]);
        }

        /** @var SuggestDepartmentsQueryResult $result */
        $result = $this->queryBus->execute(new SuggestDepartmentsQuery(new DepartmentsFilter(
            pager: Pager::fromPage($page, $limit),
            title: $q,
            companyId: '' !== $companyId ? $companyId : null,
        )));

        $items = array_map(
            static fn ($dto): array => ['id' => $dto->id, 'title' => $dto->title],
            $result->departments,
        );

        return new JsonResponse([
            'items' => $items,
            'page' => $result->pager->page,
            'hasMore' => null !== $result->pager->total_pages && $result->pager->page < $result->pager->total_pages,
        ]);
    }
}
