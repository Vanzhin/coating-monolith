<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Api\CoatingSystem;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystemsByCompliance\SearchCoatingSystemsByComplianceQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/api/coating-systems/by-compliance',
    name: 'app_api_coating_system_by_compliance',
    methods: ['GET'],
)]
class SearchByComplianceApiAction
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $standardRaw = $request->query->get('standard');
        $categoryRaw = $request->query->get('category');
        $durabilityRaw = $request->query->get('durability');
        $substrateRaw = $request->query->get('substrate');

        if (null === $standardRaw || null === $categoryRaw || null === $durabilityRaw) {
            return new JsonResponse(
                ['message' => 'Параметры standard, category и durability обязательны.'],
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json'],
            );
        }

        try {
            $standard = ComplianceStandard::from((string) $standardRaw);

            $substrate = null;
            if (is_string($substrateRaw) && '' !== $substrateRaw) {
                $substrate = Substrate::from($substrateRaw);
            }

            $result = $this->queryBus->execute(new SearchCoatingSystemsByComplianceQuery(
                standard: $standard,
                category: (string) $categoryRaw,
                durability: (string) $durabilityRaw,
                substrate: $substrate,
                page: 1,
                perPage: 50,
            ));
        } catch (\ValueError) {
            return new JsonResponse(
                ['message' => 'Неверный стандарт или субстрат.'],
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json'],
            );
        }

        $items = array_map(
            static fn (CoatingSystemDTO $dto): array => [
                'id' => $dto->id,
                'title' => $dto->title,
                'substrate' => $dto->substrate,
                'substrateTitle' => $dto->substrateTitle,
                'totalDft' => $dto->totalDft,
                'layersCount' => count($dto->layers),
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
