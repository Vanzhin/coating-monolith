<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Controller\Location;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\UseCase\Command\CreateLocation\CreateLocationCommand;
use App\Waybills\Application\UseCase\Command\CreateLocation\CreateLocationCommandResult;
use App\Waybills\Application\UseCase\Query\GetPagedLocations\GetPagedLocationsQuery;
use App\Waybills\Domain\Aggregate\Location\Location;
use App\Waybills\Domain\Repository\LocationFilter;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cabinet/waybill/locations')]
final class LocationController extends AbstractController
{
    private const int PER_PAGE = 10;

    public function __construct(private readonly QueryBusInterface $queries, private readonly CommandBusInterface $commands, private readonly LocationFormMapper $mapper)
    {
    }

    #[Route('/suggest', name: 'app_cabinet_waybill_location_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->queries->execute(new GetPagedLocationsQuery(new LocationFilter(search: $request->query->getString('q'), pager: Pager::fromPage($page, self::PER_PAGE))));

        return new JsonResponse([
            'items' => array_map(fn (Location $location): array => $this->mapper->present($location->snapshot()), $result->items),
            'page' => $page,
            'hasMore' => $page * self::PER_PAGE < $result->total,
        ]);
    }

    #[Route('/quick', name: 'app_cabinet_waybill_location_quick', methods: ['POST'])]
    public function quick(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        try {
            $result = $this->commands->execute(new CreateLocationCommand($this->mapper->fromArray($payload)));
            \assert($result instanceof CreateLocationCommandResult);
        } catch (AppException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->mapper->present($result->location), Response::HTTP_CREATED);
    }
}
