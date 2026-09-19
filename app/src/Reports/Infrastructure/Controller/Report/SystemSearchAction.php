<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemLayerDTO;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Поиск систем покрытия по названию для формы отчёта. Возвращает богатую карточку варианта:
 * слои/толщина, подложка, подготовка, среда — чтобы выбрать нужную систему из тёзок.
 */
#[Route(path: '/cabinet/report/system-search', name: 'app_cabinet_report_system_search', methods: ['GET'])]
final class SystemSearchAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $search = SearchQuery::tryFromString(trim((string) $request->query->get('q', '')));
        if (null === $search) {
            return new JsonResponse([]);
        }

        $result = $this->queryBus->execute(new SearchCoatingSystemsQuery(new CoatingSystemsFilter(search: $search)));
        \assert($result instanceof SearchCoatingSystemsQueryResult);

        $items = array_map(static fn (CoatingSystemDTO $s): array => [
            'id' => $s->id,
            'title' => $s->title,
            'substrate' => $s->substrateTitle,
            'environment' => $s->environmentTitle,
            'prep' => $s->surfaceTreatmentTitle,
            'dft' => $s->totalDft,
            'layers' => array_map(
                static fn (CoatingSystemLayerDTO $l): array => ['title' => $l->coatingTitle, 'dft' => $l->dft],
                $s->layers,
            ),
        ], $result->items);

        return new JsonResponse($items);
    }
}
