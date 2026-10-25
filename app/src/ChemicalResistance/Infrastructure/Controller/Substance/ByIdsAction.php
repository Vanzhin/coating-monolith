<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Application\UseCase\Query\GetSubstancesByIds\GetSubstancesByIdsQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetSubstancesByIds\GetSubstancesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Лёгкий JSON-хелпер: {id, title} по списку id веществ. Кормит Stimulus,
 * который восстанавливает чипы фасета «Вещества» после загрузки страницы —
 * в URL лежат только id (shareable-ссылка), названия дотягиваются отсюда.
 * Зеркало Coatings/Infrastructure/Controller/Coating/ByIdsAction.
 */
#[Route(
    path: '/cabinet/chemical-resistance/substance/by-ids',
    name: 'app_cabinet_chemical_resistance_substance_by_ids',
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

        /** @var GetSubstancesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetSubstancesByIdsQuery(new StringCollection(...array_slice($ids, 0, self::MAX_IDS))));

        $items = array_map(
            static fn ($ref) => ['id' => $ref->id, 'title' => $ref->canonicalName],
            $result->substances,
        );

        return new JsonResponse(['items' => $items]);
    }
}
