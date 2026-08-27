<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Application\UseCase\Query\ListSurfaceTreatments\ListSurfaceTreatmentsQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/surface-treatment/suggest',
    name: 'app_cabinet_surface_treatment_suggest',
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
        $substrateRaw = $request->query->get('substrate');
        $substrate = is_string($substrateRaw) ? Substrate::tryFrom($substrateRaw) : null;
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

        if ('' === $q) {
            return new JsonResponse(['items' => []]);
        }

        $filter = new SurfaceTreatmentsFilter(substrate: $substrate, q: $q);

        /** @var array{items: list<\App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTO>, total: int} $result */
        $result = $this->queryBus->execute(new ListSurfaceTreatmentsQuery($filter, 1, $limit));

        $items = array_map(
            static fn ($dto) => [
                'id' => $dto->id,
                'title' => $dto->title,
                'description' => $dto->description,
                'code' => $dto->code,
                'standardCode' => $dto->standardCode,
                'substrateScope' => $dto->substrateScope,
            ],
            $result['items'],
        );

        return new JsonResponse(['items' => $items]);
    }
}
