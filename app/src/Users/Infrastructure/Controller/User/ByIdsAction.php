<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\User;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Users\Application\DTO\UserSuggestDTO;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQuery;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQueryResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Ulid;

/**
 * Лёгкий JSON-хелпер: {id, title} по списку id юзеров (email в title). Кормит
 * Stimulus, который восстанавливает чипы фасета «Актор» админ-журнала после
 * загрузки страницы — в URL лежат только id (shareable-ссылка), email
 * дотягивается отсюда. Зеркалит Coatings\Coating\ByIdsAction; id юзера — ulid,
 * не uuid, поэтому валидация — Ulid::isValid.
 */
#[Route(
    path: '/cabinet/users/by-ids',
    name: 'app_cabinet_users_by_ids',
    methods: ['GET'],
)]
final class ByIdsAction extends AbstractController
{
    private const MAX_IDS = 50;

    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $ids = array_values(array_filter(
            array_map('strval', $request->query->all('ids')),
            static fn (string $id): bool => Ulid::isValid($id),
        ));

        if ([] === $ids) {
            return new JsonResponse(['items' => []]);
        }

        /** @var GetUsersByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetUsersByIdsQuery(new StringCollection(...array_slice($ids, 0, self::MAX_IDS))));

        $items = array_map(
            static fn (UserSuggestDTO $user): array => ['id' => $user->id, 'title' => $user->title],
            $result->users,
        );

        return new JsonResponse(['items' => $items]);
    }
}
