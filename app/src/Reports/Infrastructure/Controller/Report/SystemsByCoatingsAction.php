<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Подбор систем покрытия по введённым покрытиям (OR: система с любым из них). Для формы отчёта:
 * вводишь покрытия — предлагаются системы с подложкой/подготовкой/средой. Пусто → пустой список.
 */
#[Route(path: '/cabinet/report/systems-by-coatings', name: 'app_cabinet_report_systems_by_coatings', methods: ['GET'])]
final class SystemsByCoatingsAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $ids = array_values(array_filter(
            array_map(static fn (mixed $v): string => (string) $v, $request->query->all('coatingIds')),
            static fn (string $s): bool => Uuid::isValid($s),
        ));
        if ([] === $ids) {
            return new JsonResponse([]);
        }

        $result = $this->queryBus->execute(new SearchCoatingSystemsQuery(
            new CoatingSystemsFilter(coatingIds: new StringCollection(...$ids)),
        ));
        \assert($result instanceof SearchCoatingSystemsQueryResult);

        $items = array_map(static fn (CoatingSystemDTO $s): array => [
            'id' => $s->id,
            'title' => $s->title,
            'substrate' => $s->substrateTitle,
            'environment' => $s->environmentTitle,
            'prep' => $s->surfaceTreatmentTitle,
            'dft' => $s->totalDft,
        ], $result->items);

        return new JsonResponse($items);
    }
}
