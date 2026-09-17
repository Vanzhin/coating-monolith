<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\User;

use App\Shared\Application\Query\QueryBusInterface;
use App\Users\Application\DTO\UserSuggestDTO;
use App\Users\Application\UseCase\Query\SearchUsers\SearchUsersQuery;
use App\Users\Application\UseCase\Query\SearchUsers\SearchUsersQueryResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Typeahead по email для фасета «Актор» админ-журнала. Зеркалит
 * Coatings\Coating\SuggestAction. Доступ — только управляющему (гейт в
 * SearchUsersQueryHandler через AuditAccessControl): эндпоинт отдаёт email.
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
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        /** @var SearchUsersQueryResult $result */
        $result = $this->queryBus->execute(new SearchUsersQuery($q, $limit));

        $items = array_map(
            static fn (UserSuggestDTO $user): array => ['id' => $user->id, 'title' => $user->title],
            $result->users,
        );

        return new JsonResponse(['items' => $items]);
    }
}
