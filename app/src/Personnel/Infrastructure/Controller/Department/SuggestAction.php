<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Query\SuggestDepartments\SuggestDepartmentsQuery;
use App\Personnel\Application\UseCase\Query\SuggestDepartments\SuggestDepartmentsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * typeahead отделов (напр. будущая форма профиля сотрудника). Read-запрос не гейтим —
 * просмотр справочника открыт всем авторизованным.
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
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        $result = $this->queryBus->execute(new SuggestDepartmentsQuery($q, $limit));
        \assert($result instanceof SuggestDepartmentsQueryResult);

        $items = array_map(
            static fn ($dto) => ['id' => $dto->id, 'title' => $dto->title],
            $result->departments,
        );

        return new JsonResponse(['items' => $items]);
    }
}
