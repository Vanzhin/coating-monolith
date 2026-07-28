<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\SurfaceTreatment;

use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Cabinet-endpoint для inline-создания подготовки поверхности прямо из формы CoatingSystem.
 * Отличается от AddAction: принимает JSON, возвращает JSON DTO (не редиректит на list),
 * работает под session-auth (не JWT) — годен для fetch из cabinet-браузера.
 */
#[Route(
    path: '/cabinet/coating/surface-treatment/create-inline',
    name: 'app_cabinet_surface_treatment_create_inline',
    methods: ['POST'],
)]
class CreateInlineAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $payload = json_decode((string) $request->getContent(), true);
            if (!\is_array($payload)) {
                throw new AppException('Некорректный JSON.');
            }

            $description = trim((string) ($payload['description'] ?? ''));
            $code = isset($payload['code']) && '' !== trim((string) $payload['code']) ? trim((string) $payload['code']) : null;
            $standardCode = isset($payload['standardCode']) && '' !== trim((string) $payload['standardCode']) ? trim((string) $payload['standardCode']) : null;

            $rawScope = $payload['substrateScope'] ?? [];
            if (!\is_array($rawScope) || [] === $rawScope) {
                throw new AppException('Не указан scope subtrate.');
            }
            $scope = [];
            foreach ($rawScope as $s) {
                $scope[] = Substrate::from((string) $s);
            }

            $command = new CreateSurfaceTreatmentCommand($description, $code, $standardCode, $scope);
            $result = $this->commandBus->execute($command);
            $dto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($result->id));

            if (null === $dto) {
                throw new AppException('Не удалось получить созданную подготовку поверхности.');
            }

            return new JsonResponse([
                'id' => $dto->id,
                'code' => $dto->code,
                'description' => $dto->description,
                'standardCode' => $dto->standardCode,
                'substrateScope' => $dto->substrateScope,
                'title' => $dto->title,
            ], Response::HTTP_CREATED);
        } catch (\ValueError $e) {
            return new JsonResponse(['message' => 'Некорректное значение substrate.'], Response::HTTP_BAD_REQUEST);
        }
    }
}
