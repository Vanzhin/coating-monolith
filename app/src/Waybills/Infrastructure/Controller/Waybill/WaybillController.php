<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Controller\Waybill;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\TemplateRendering;
use App\Waybills\Application\DTO\WaybillEditData;
use App\Waybills\Application\UseCase\Command\DeleteWaybill\DeleteWaybillCommand;
use App\Waybills\Application\UseCase\Command\LockWaybill\LockWaybillCommand;
use App\Waybills\Application\UseCase\Query\GetPagedVehicles\GetPagedVehiclesQuery;
use App\Waybills\Application\UseCase\Query\GetPagedWaybills\GetPagedWaybillsQuery;
use App\Waybills\Application\UseCase\Query\GetVehicle\GetVehicleQuery;
use App\Waybills\Application\UseCase\Query\GetWaybillForEdit\GetWaybillForEditQuery;
use App\Waybills\Application\UseCase\Query\GetWaybillRenderData\GetWaybillRenderDataQuery;
use App\Waybills\Domain\Repository\VehicleFilter;
use App\Waybills\Domain\Repository\WaybillFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cabinet/waybill/sheets')]
final class WaybillController extends AbstractController
{
    private const int PER_PAGE = 10;

    public function __construct(private readonly QueryBusInterface $queries, private readonly CommandBusInterface $commands, private readonly WaybillFormMapper $mapper, private readonly TemplateRendering $rendering, private readonly string $waybillTemplatePath)
    {
    }

    #[Route('', name: 'app_cabinet_waybill_sheet_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $vehicleId = $request->query->getString('vehicleId');
        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->queries->execute(new GetPagedWaybillsQuery(new WaybillFilter(vehicleId: '' === $vehicleId ? null : $vehicleId, pager: Pager::fromPage($page, self::PER_PAGE))));

        return $this->render('cabinet/waybill/sheet/list.html.twig', [
            'items' => $result->items, 'total' => $result->total, 'vehicleId' => $vehicleId,
            'page' => $page, 'perPage' => self::PER_PAGE,
        ]);
    }

    #[Route('/new', name: 'app_cabinet_waybill_sheet_new', methods: ['GET'])]
    public function new(): Response
    {
        $vehicles = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter(pager: Pager::fromPage(1, 1)), currentDriverOnly: true));
        $vehicle = $vehicles->items[0] ?? null;
        if (null === $vehicle) {
            $this->addFlash('warning', 'Чтобы создать путевой лист, сначала сядьте в автомобиль.');

            return $this->redirectToRoute('app_cabinet_waybill_vehicle_list');
        }

        return $this->redirectToRoute('app_cabinet_waybill_sheet_create', ['vehicleId' => $vehicle->id]);
    }

    #[Route('/new/{vehicleId}', name: 'app_cabinet_waybill_sheet_create', methods: ['GET', 'POST'])]
    public function create(Request $request, string $vehicleId): Response
    {
        $vehicle = $this->queries->execute(new GetVehicleQuery($vehicleId));
        $input = $this->mapper->createInput($vehicle);
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            try {
                $this->postedInput($request, $input, creating: true);
                $this->checkToken($request, 'waybill_new_'.$vehicleId);
                if ('refresh_readings' === $request->request->getString('action')) {
                    $fresh = $this->mapper->createInput($vehicle);
                    $input['initialOdometer'] = $fresh['initialOdometer'];
                    $input['initialFuel'] = $fresh['initialFuel'];
                } else {
                    $id = $this->commands->execute($this->mapper->createCommand($vehicleId, $input));

                    return $this->redirectToRoute('app_cabinet_waybill_sheet_show', ['id' => $id]);
                }
            } catch (AppException $exception) {
                $error = $exception->getMessage();
                $status = $exception->getCode();
            }
        }

        return $this->render('cabinet/waybill/sheet/create.html.twig', [
            'vehicle' => $vehicle, 'input' => $input, 'error' => $error, 'timezone' => date_default_timezone_get(),
        ], new Response(status: $status));
    }

    #[Route('/{id}', name: 'app_cabinet_waybill_sheet_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(string $id): Response
    {
        $editData = $this->getWaybill($id);

        return $this->render('cabinet/waybill/sheet/show.html.twig', ['waybill' => $editData->waybill, 'version' => $editData->version]);
    }

    #[Route('/{id}/delete', name: 'app_cabinet_waybill_sheet_delete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function delete(Request $request, string $id): Response
    {
        try {
            $this->checkToken($request, 'waybill_delete_'.$id);
            $this->commands->execute(new DeleteWaybillCommand($id, $this->postedVersion($request)));
            $this->addFlash('success', 'Черновик путевого листа удалён.');

            return $this->redirectToRoute('app_cabinet_waybill_sheet_list');
        } catch (AppException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('app_cabinet_waybill_sheet_show', ['id' => $id]);
        }
    }

    #[Route('/{id}/approve', name: 'app_cabinet_waybill_sheet_approve', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function approve(Request $request, string $id): Response
    {
        try {
            $this->checkToken($request, 'waybill_approve_'.$id);
            $this->commands->execute(new LockWaybillCommand($id, $this->postedVersion($request)));
            $this->addFlash('success', 'Путевой лист утверждён. Изменение и удаление больше недоступны.');
        } catch (AppException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_waybill_sheet_show', ['id' => $id]);
    }

    #[Route('/{id}/download', name: 'app_cabinet_waybill_sheet_download', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function download(string $id): Response
    {
        $data = $this->queries->execute(new GetWaybillRenderDataQuery($id));
        $number = $data->get('number');
        $number = $number instanceof TextValue ? $number->value : '';
        try {
            $document = $this->rendering->render(new TemplateFile($this->waybillTemplatePath), $data);
        } catch (AppException $exception) {
            $this->addFlash('danger', 'Не удалось сформировать XLSX: '.$exception->getMessage());

            return $this->redirectToRoute('app_cabinet_waybill_sheet_show', ['id' => $id]);
        }
        $response = new Response($document->content, headers: ['Content-Type' => $document->mimeType()]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'путевой-лист-'.$number.'.'.$document->extension(),
            'waybill-'.$number.'.'.$document->extension(),
        ));

        return $response;
    }

    #[Route('/{id}/edit', name: 'app_cabinet_waybill_sheet_edit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $id): Response
    {
        $editData = $this->queries->execute(new GetWaybillForEditQuery($id));
        $waybill = $editData->waybill;
        $waybill->assertEditable();
        $input = $this->mapper->input($waybill, $editData->version);
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            try {
                $this->postedInput($request, $input);
                $this->checkToken($request, 'waybill_edit_'.$id);
                $this->commands->execute($this->mapper->updateCommand($waybill, $input));

                return $this->redirectToRoute('app_cabinet_waybill_sheet_show', ['id' => $id]);
            } catch (AppException $exception) {
                $error = $exception->getMessage();
                $status = $exception->getCode();
            }
        }

        return $this->render('cabinet/waybill/sheet/edit.html.twig', [
            'waybill' => $waybill, 'input' => $input, 'error' => $error, 'timezone' => date_default_timezone_get(),
        ], new Response(status: $status));
    }

    /** @param array<string, mixed> $input */
    private function postedInput(Request $request, array &$input, bool $creating = false): void
    {
        $keys = ['from', 'to', 'odometerEnd', 'fuelRemaining'];
        array_push($keys, ...($creating ? ['initialOdometer', 'initialFuel'] : ['version']));
        $missing = false;
        foreach ($keys as $key) {
            if (!$request->request->has($key)) {
                $missing = true;
                continue;
            }
            $input[$key] = $request->request->getString($key);
        }
        $input['trips'] = [];
        foreach ($request->request->all('trips') as $row) {
            if (!is_array($row)) {
                throw new AppException('Некорректные данные поездки.');
            }
            $trip = [];
            foreach ($this->mapper->blankTrip() as $key => $unused) {
                $value = $row[$key] ?? '';
                if (!is_string($value)) {
                    throw new AppException('Некорректные данные поездки.');
                }
                $trip[$key] = $value;
            }
            $input['trips'][] = $trip;
        }
        $input['refuelings'] = [];
        foreach ($request->request->all('refuelings') as $row) {
            if (!is_array($row)) {
                throw new AppException('Некорректные данные заправки.');
            }
            $refueling = [];
            foreach ($this->mapper->blankRefueling() as $key => $unused) {
                $value = $row[$key] ?? '';
                if (!is_string($value)) {
                    throw new AppException('Некорректные данные заправки.');
                }
                $refueling[$key] = $value;
            }
            $input['refuelings'][] = $refueling;
        }
        $input['absences'] = [];
        foreach ($request->request->all('absences') as $row) {
            if (!is_array($row)) {
                throw new AppException('Некорректные данные периода без поездок.');
            }
            $absence = [];
            foreach ($this->mapper->blankAbsence() as $key => $unused) {
                $value = $row[$key] ?? '';
                if (!is_string($value)) {
                    throw new AppException('Некорректные данные периода без поездок.');
                }
                $absence[$key] = $value;
            }
            $input['absences'][] = $absence;
        }
        if ($missing) {
            throw new AppException('Форма заполнена не полностью. Обновите страницу и повторите операцию.');
        }
        if ('1' !== $request->request->getString('tripsSubmitted')) {
            throw new AppException('Список поездок не был передан. Обновите страницу и повторите операцию.');
        }
        if ('1' !== $request->request->getString('refuelingsSubmitted')) {
            throw new AppException('Список заправок не был передан. Обновите страницу и повторите операцию.');
        }
        if ('1' !== $request->request->getString('absencesSubmitted')) {
            throw new AppException('Список периодов без поездок не был передан. Обновите страницу и повторите операцию.');
        }
    }

    private function postedVersion(Request $request): int
    {
        $version = filter_var($request->request->getString('version'), FILTER_VALIDATE_INT);
        if (false === $version || $version < 1) {
            throw new AppException('Версия путевого листа не указана. Обновите страницу.');
        }

        return $version;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AppException('Сессия устарела, попробуйте ещё раз.', Response::HTTP_FORBIDDEN);
        }
    }

    private function getWaybill(string $id): WaybillEditData
    {
        try {
            return $this->queries->execute(new GetWaybillForEditQuery($id));
        } catch (AppException $exception) {
            if (Response::HTTP_NOT_FOUND === $exception->getCode()) {
                throw $this->createNotFoundException($exception->getMessage(), $exception);
            }
            throw $exception;
        }
    }
}
