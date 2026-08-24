<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Лёгкий JSON-хелпер: {id, title} по списку id покрытий. Кормит Stimulus,
 * который восстанавливает чипы фасета «Покрытия» после загрузки страницы —
 * в URL лежат только id (shareable-ссылка), названия дотягиваются отсюда.
 */
#[Route(
    path: '/cabinet/coating/coating/by-ids',
    name: 'app_cabinet_coating_coating_by_ids',
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
            array_map('strval', (array) $request->query->all('ids')),
            static fn (string $id) => Uuid::isValid($id),
        ));

        if ([] === $ids) {
            return new JsonResponse(['items' => []]);
        }

        /** @var GetCoatingsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingsByIdsQuery(new StringCollection(...array_slice($ids, 0, self::MAX_IDS))));

        $items = array_map(
            static fn ($coating) => ['id' => $coating->id, 'title' => $coating->title],
            $result->coatings,
        );

        return new JsonResponse(['items' => $items]);
    }
}
