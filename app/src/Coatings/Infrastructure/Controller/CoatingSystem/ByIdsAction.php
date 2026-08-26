<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Query\GetCoatingSystemsByIds\GetCoatingSystemsByIdsQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingSystemsByIds\GetCoatingSystemsByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Лёгкий JSON-хелпер: {id, title} по списку id систем. Кормит Stimulus, который
 * восстанавливает названия систем-референсов из голых id (форма/список документов,
 * кросс-контекст: имена тянутся из Coatings по HTTP). Зеркаль Coating\ByIdsAction.
 */
#[Route(
    path: '/cabinet/coating/coating-system/by-ids',
    name: 'app_cabinet_coating_system_by_ids',
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
            static fn (string $id) => Uuid::isValid($id),
        ));

        if ([] === $ids) {
            return new JsonResponse(['items' => []]);
        }

        /** @var GetCoatingSystemsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingSystemsByIdsQuery(new StringCollection(...array_slice($ids, 0, self::MAX_IDS))));

        $items = array_map(
            static fn ($system) => ['id' => $system->id, 'title' => $system->title],
            $result->systems,
        );

        return new JsonResponse(['items' => $items]);
    }
}
