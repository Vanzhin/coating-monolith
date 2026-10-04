<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\DTO\Department\DepartmentDTO;
use App\Personnel\Application\UseCase\Query\GetDepartmentsByIds\GetDepartmentsByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetDepartmentsByIds\GetDepartmentsByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Восстановление чипов (напр. выбранный родитель): {id,title} по списку id. Зеркалит
 * Position\ByIdsAction; id отдела — uuid.
 */
#[Route(
    path: '/cabinet/personnel/department/by-ids',
    name: 'app_cabinet_personnel_department_by_ids',
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
            static fn (string $id): bool => Uuid::isValid($id),
        ));

        if ([] === $ids) {
            return new JsonResponse(['items' => []]);
        }

        $result = $this->queryBus->execute(
            new GetDepartmentsByIdsQuery(new StringCollection(...array_slice($ids, 0, self::MAX_IDS))),
        );
        \assert($result instanceof GetDepartmentsByIdsQueryResult);

        $items = array_map(
            static fn (DepartmentDTO $dto): array => ['id' => $dto->id, 'title' => $dto->title],
            $result->departments,
        );

        return new JsonResponse(['items' => $items]);
    }
}
