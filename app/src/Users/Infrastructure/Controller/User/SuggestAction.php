<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\User;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Users\Application\DTO\UserSuggestDTO;
use App\Users\Application\UseCase\Query\SearchUsers\SearchUsersQuery;
use App\Users\Application\UseCase\Query\SearchUsers\SearchUsersQueryResult;
use App\Users\Domain\Repository\UsersFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Постраничный typeahead по email (фасет «Актор» админ-журнала + пикер юзера в форме профиля).
 * Поиск — через UsersFilter, отдаёт {items, page, hasMore}. Доступ — только управляющему
 * (гейт в SearchUsersQueryHandler через AuditAccessControl): эндпоинт отдаёт email.
 */
#[Route(
    path: '/cabinet/users/suggest',
    name: 'app_cabinet_users_suggest',
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
        // hasProfile: есть ли профиль сотрудника. Параметр отсутствует → null (все — как раньше, аудит-фасет
        // не ломается). Пикер привязки профиля шлёт 0 → только без профиля.
        $hasProfile = $request->query->has('hasProfile') ? $request->query->getBoolean('hasProfile') : null;

        if ('' === $q) {
            return new JsonResponse(['items' => [], 'page' => 1, 'hasMore' => false]);
        }

        /** @var SearchUsersQueryResult $result */
        $result = $this->queryBus->execute(new SearchUsersQuery(new UsersFilter(
            pager: Pager::fromPage($page, $limit),
            email: $q,
            hasProfile: $hasProfile,
        )));

        $items = array_map(
            static fn (UserSuggestDTO $user): array => ['id' => $user->id, 'title' => $user->title],
            $result->users,
        );

        return new JsonResponse([
            'items' => $items,
            'page' => $result->pager->page,
            'hasMore' => null !== $result->pager->total_pages && $result->pager->page < $result->pager->total_pages,
        ]);
    }
}
