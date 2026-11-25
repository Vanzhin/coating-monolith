<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Controller\Vehicle;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\VehicleDTO;
use App\Waybills\Application\DTO\VehicleListItem;
use App\Waybills\Application\UseCase\Command\EnterVehicle\EnterVehicleCommand;
use App\Waybills\Application\UseCase\Command\LeaveVehicle\LeaveVehicleCommand;
use App\Waybills\Application\UseCase\Query\GetPagedVehicles\GetPagedVehiclesQuery;
use App\Waybills\Application\UseCase\Query\GetVehicle\GetVehicleQuery;
use App\Waybills\Domain\Aggregate\Route\SettlementType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Repository\VehicleFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cabinet/waybill/vehicle')]
final class VehicleController extends AbstractController
{
    private const PER_PAGE = 10;

    public function __construct(private readonly QueryBusInterface $queries, private readonly CommandBusInterface $commands, private readonly VehicleFormMapper $mapper)
    {
    }

    #[Route('', name: 'app_cabinet_waybill_vehicle_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $q = $request->query->getString('q');
        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter($q, Pager::fromPage($page, self::PER_PAGE))));

        return $this->render('cabinet/waybill/vehicle/list.html.twig', [
            'items' => $result->items, 'total' => $result->total, 'page' => $page, 'perPage' => self::PER_PAGE, 'q' => $q,
        ]);
    }

    #[Route('/suggest', name: 'app_cabinet_waybill_vehicle_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter($request->query->getString('q'), Pager::fromPage($page, self::PER_PAGE))));

        // ResponseListener оборачивает только application/json без charset; typeahead ждёт плоский ответ.
        return new JsonResponse([
            'items' => array_map(static fn (VehicleListItem $vehicle): array => [
                'id' => $vehicle->id, 'title' => $vehicle->model.' — '.strtoupper($vehicle->registrationNumber),
            ], $result->items),
            'page' => $page, 'hasMore' => $page * self::PER_PAGE < $result->total,
        ], headers: ['Content-Type' => 'application/json; charset=utf-8']);
    }

    #[Route('/{id}/preview', name: 'app_cabinet_waybill_vehicle_preview', methods: ['GET'])]
    public function preview(string $id): Response
    {
        return $this->render('cabinet/waybill/vehicle/_preview.html.twig', ['vehicle' => $this->getVehicle($id)]);
    }

    #[Route('/{id}', name: 'app_cabinet_waybill_vehicle_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id): Response
    {
        return $this->render('cabinet/waybill/vehicle/show.html.twig', ['vehicle' => $this->getVehicle($id)]);
    }

    #[Route('/{id}/enter', name: 'app_cabinet_waybill_vehicle_enter', methods: ['POST'])]
    public function enter(Request $request, string $id): Response
    {
        return $this->changeOccupancy($request, $id, new EnterVehicleCommand($id));
    }

    #[Route('/{id}/leave', name: 'app_cabinet_waybill_vehicle_leave', methods: ['POST'])]
    public function leave(Request $request, string $id): Response
    {
        return $this->changeOccupancy($request, $id, new LeaveVehicleCommand($id));
    }

    private function changeOccupancy(Request $request, string $id, CommandInterface $command): Response
    {
        if (!$this->isCsrfTokenValid('vehicle_occupancy_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен.');
        }
        try {
            $this->commands->execute($command);
        } catch (AppException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_waybill_vehicle_list', [
            'q' => $request->request->getString('q'),
            'page' => max(1, $request->request->getInt('page', 1)),
        ]);
    }

    #[Route('/new', name: 'app_cabinet_waybill_vehicle_create', methods: ['GET', 'POST'])]
    #[Route('/{id}/edit', name: 'app_cabinet_waybill_vehicle_edit', methods: ['GET', 'POST'])]
    public function form(Request $request, ?string $id = null): Response
    {
        $vehicle = null === $id ? null : $this->getVehicle($id);
        $input = null === $id ? $this->mapper->newVehicleInput() : $this->mapper->vehicleInput($vehicle);
        $selectedFuelTypes = null === $id ? [FuelType::Ai95->value] : $vehicle->fuelTypes;
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $input = $this->readInput($request, array_keys($input));
            $selectedFuelTypes = $request->request->all('fuelTypes');
            try {
                if (!$this->isCsrfTokenValid('vehicle_'.($id ?? 'new'), $request->request->getString('_token'))) {
                    throw new AppException('Сессия устарела, попробуйте ещё раз.', Response::HTTP_FORBIDDEN);
                }
                $command = null === $id
                    ? $this->mapper->createVehicleCommand($input, $selectedFuelTypes)
                    : $this->mapper->updateVehicleCommand($id, $input, $selectedFuelTypes);
                $this->commands->execute($command);

                return $this->redirectToRoute('app_cabinet_waybill_vehicle_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
                $status = $e->getCode();
            }
        }

        return $this->render('cabinet/waybill/vehicle/form.html.twig', [
            'input' => $input, 'id' => $id, 'error' => $error, 'fuelTypes' => FuelType::cases(),
            'selectedFuelTypes' => $selectedFuelTypes, 'timezone' => date_default_timezone_get(), 'settlementTypes' => SettlementType::cases(),
        ], new Response(status: $status));
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function readInput(Request $request, array $keys): array
    {
        $input = [];
        foreach ($keys as $key) {
            $input[$key] = $request->request->getString($key);
        }

        return $input;
    }

    private function getVehicle(string $id): VehicleDTO
    {
        try {
            return $this->queries->execute(new GetVehicleQuery($id));
        } catch (AppException $e) {
            if (Response::HTTP_NOT_FOUND === $e->getCode()) {
                throw $this->createNotFoundException($e->getMessage(), $e);
            }
            throw $e;
        }
    }
}
