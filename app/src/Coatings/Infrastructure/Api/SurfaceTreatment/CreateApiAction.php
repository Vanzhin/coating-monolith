<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Api\SurfaceTreatment;

use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/api/surface-treatments',
    name: 'api_surface_treatments_create',
    methods: ['POST'],
)]
class CreateApiAction
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $description = $body['description'] ?? null;
        $substrateScopeRaw = $body['substrateScope'] ?? [];

        if (empty($description) || !is_string($description)) {
            return new JsonResponse(
                ['message' => 'Поле description обязательно.'],
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json'],
            );
        }

        if (empty($substrateScopeRaw) || !is_array($substrateScopeRaw)) {
            return new JsonResponse(
                ['message' => 'Поле substrateScope обязательно и не должно быть пустым.'],
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json'],
            );
        }

        try {
            $substrateScope = array_map(
                static fn (string $value): Substrate => Substrate::from($value),
                array_values($substrateScopeRaw),
            );
        } catch (\ValueError) {
            return new JsonResponse(
                ['message' => 'Неверное значение substrate.'],
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'application/json'],
            );
        }

        $result = $this->commandBus->execute(new CreateSurfaceTreatmentCommand(
            description: $description,
            code: isset($body['code']) && '' !== $body['code'] ? (string) $body['code'] : null,
            standardCode: isset($body['standardCode']) && '' !== $body['standardCode'] ? (string) $body['standardCode'] : null,
            substrateScope: $substrateScope,
        ));

        $dto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($result->id));

        return new JsonResponse($dto, Response::HTTP_CREATED, ['Content-Type' => 'application/json']);
    }
}
