<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Api\SurfaceTreatment;

use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTO;
use App\Coatings\Application\UseCase\Query\ListSurfaceTreatments\ListSurfaceTreatmentsQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/api/surface-treatments',
    name: 'api_surface_treatments_list',
    methods: ['GET'],
)]
class ListApiAction
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $substrateRaw = $request->query->get('substrate');
        $q = $request->query->get('q');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(100, (int) $request->query->get('perPage', 50)));

        try {
            $substrate = null;
            if (is_string($substrateRaw) && '' !== $substrateRaw) {
                $substrate = Substrate::from($substrateRaw);
            }
        } catch (\ValueError) {
            return new JsonResponse(
                ['message' => 'Неверное значение substrate.'],
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json'],
            );
        }

        $result = $this->queryBus->execute(new ListSurfaceTreatmentsQuery(
            filter: new SurfaceTreatmentsFilter(
                substrate: $substrate,
                q: is_string($q) && '' !== $q ? $q : null,
            ),
            page: $page,
            perPage: $perPage,
        ));

        $items = array_map(
            static fn (SurfaceTreatmentDTO $dto): array => [
                'id' => $dto->id,
                'description' => $dto->description,
                'code' => $dto->code,
                'standardCode' => $dto->standardCode,
                'substrateScope' => $dto->substrateScope,
                'title' => $dto->title,
            ],
            $result['items'],
        );

        return new JsonResponse(
            ['items' => $items],
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }
}
