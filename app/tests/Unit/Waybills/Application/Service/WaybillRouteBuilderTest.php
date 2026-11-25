<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Application\Service;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\OdometerRange;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use App\Waybills\Domain\Service\Calendar\ProductionCalendar;
use App\Waybills\Domain\Service\WaybillRouteFiller;
use App\Waybills\Infrastructure\Controller\Waybill\WaybillFormMapper;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRouteBuilderTest extends TestCase
{
    private WaybillRouteBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->createStub(WaybillRepositoryInterface::class);
        $repository->method('hasExistingTripIds')->willReturn(false);
        $this->builder = new WaybillRouteBuilder($repository);
    }

    #[DataProvider('builderMethods')]
    public function test_known_and_null_ids_do_not_query_global_trips(string $method): void
    {
        $waybill = $this->draft();
        $known = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Россия', 'Первая'), 10), RouteLogOrigin::Manual);
        $waybill->setTrips($known);
        $inputs = [
            new TripInput($known->id, $known->date, $known->destination),
            new TripInput(null, $known->date, new Destination(new Location('Россия', 'Вторая'), 10)),
        ];
        $repository = $this->createMock(WaybillRepositoryInterface::class);
        $repository->expects(self::never())->method('hasExistingTripIds');
        $builder = new WaybillRouteBuilder($repository);

        $logs = $this->logs($builder->$method($waybill, null, true, ...$inputs));

        self::assertCount(2, $logs);
        self::assertEquals($known->id, $logs[0]->id);
        self::assertNotEquals($known->id, $logs[1]->id);
        self::assertNull($builder->$method($waybill, null, false));
    }

    #[DataProvider('builderMethods')]
    public function test_saved_automatic_pair_ids_do_not_query_global_trips(string $method): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $waybill->setTrips($outward, $back);
        $pairId = Uuid::v7();
        $inputs = [
            new TripInput($outward->id, $outward->date, $outward->destination, RouteLogOrigin::Automatic, $pairId),
            new TripInput($back->id, $back->date, $back->destination, RouteLogOrigin::Automatic, $pairId),
        ];
        $repository = $this->createMock(WaybillRepositoryInterface::class);
        $repository->expects(self::never())->method('hasExistingTripIds');

        $logs = $this->logs((new WaybillRouteBuilder($repository))->$method($waybill, null, true, ...$inputs));

        self::assertCount('build' === $method ? 2 : 0, $logs);
    }

    #[DataProvider('occupiedIdCases')]
    public function test_occupied_id_is_rejected_regardless_of_origin_or_snapshot(string $method, RouteLogOrigin $origin): void
    {
        $waybill = $this->draft();
        $id = Uuid::v7();
        $date = new \DateTimeImmutable('2026-01-10');
        $destination = new Destination(new Location('Россия', 'Чужая'), 10);
        $pairId = Uuid::v7();
        $inputs = [new TripInput($id, $date, $destination, $origin, $pairId, $date, $destination)];
        if (RouteLogOrigin::Automatic === $origin) {
            $back = new Destination($waybill->vehicle()->initialLocation(), 10);
            $inputs[] = new TripInput(null, $date, $back, $origin, $pairId, $date, $back);
        }
        $repository = $this->createMock(WaybillRepositoryInterface::class);
        $repository->expects(self::once())->method('hasExistingTripIds')->with(new StringCollection((string) $id))->willReturn(true);
        $builder = new WaybillRouteBuilder($repository);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Поездка не принадлежит этому черновику.');
        $builder->$method($waybill, null, true, ...$inputs);
    }

    #[DataProvider('builderMethods')]
    public function test_occupied_automatic_id_is_checked_before_missing_snapshot(string $method): void
    {
        $waybill = $this->draft();
        $id = Uuid::v7();
        $input = new TripInput($id, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Россия', 'Чужая'), 10), RouteLogOrigin::Automatic);
        $repository = $this->createStub(WaybillRepositoryInterface::class);
        $repository->method('hasExistingTripIds')->willReturn(true);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Поездка не принадлежит этому черновику.');
        (new WaybillRouteBuilder($repository))->$method($waybill, null, true, $input);
    }

    #[DataProvider('builderMethods')]
    public function test_unknown_ids_are_checked_in_one_batch_before_automatic_trips_are_discarded(string $method): void
    {
        $waybill = $this->draft();
        $date = new \DateTimeImmutable('2026-01-10');
        $known = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, $date, new Destination(new Location('Россия', 'Первая'), 10), RouteLogOrigin::Manual);
        $waybill->setTrips($known);
        $manualDestination = new Destination(new Location('Россия', 'Вторая'), 10);
        $outward = new Destination(new Location('Россия', 'Клиент'), 10);
        $back = new Destination($manualDestination->location, 10);
        $pairId = Uuid::v7();
        $firstId = Uuid::v7();
        $secondId = Uuid::v7();
        $inputs = [
            new TripInput($known->id, $date, $known->destination),
            new TripInput(null, $date, $manualDestination),
            new TripInput($firstId, $date, $outward, RouteLogOrigin::Automatic, $pairId, $date, $outward),
            new TripInput($secondId, $date, $back, RouteLogOrigin::Automatic, $pairId, $date, $back),
        ];
        $repository = $this->createMock(WaybillRepositoryInterface::class);
        $repository->expects(self::once())->method('hasExistingTripIds')->with(new StringCollection((string) $firstId, (string) $secondId))->willReturn(false);

        $logs = $this->logs((new WaybillRouteBuilder($repository))->$method($waybill, null, true, ...$inputs));

        self::assertCount('build' === $method ? 4 : 2, $logs);
        self::assertEquals($known->id, $logs[0]->id);
    }

    /** @return iterable<string, array{string}> */
    public static function builderMethods(): iterable
    {
        yield 'полный маршрут' => ['build'];
        yield 'фиксированный маршрут' => ['buildFixed'];
    }

    /** @return iterable<string, array{string, RouteLogOrigin}> */
    public static function occupiedIdCases(): iterable
    {
        yield 'полный маршрут, ручная со снимком' => ['build', RouteLogOrigin::Manual];
        yield 'полный маршрут, автоматическая' => ['build', RouteLogOrigin::Automatic];
        yield 'фиксированный маршрут, ручная со снимком' => ['buildFixed', RouteLogOrigin::Manual];
        yield 'фиксированный маршрут, удаляемая автоматическая' => ['buildFixed', RouteLogOrigin::Automatic];
    }

    public function test_saved_automatic_pair_becomes_manual_after_edit(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $waybill->setTrips($outward, $back);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['trips'][0]['distance'] = '11';

        $route = $this->builder->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($route);

        self::assertCount(2, $logs);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertSame(11, $logs[0]->distanceInKilometers());
        self::assertEquals($outward->id, $logs[0]->id);
        self::assertEquals($back->id, $logs[1]->id);
    }

    public function test_unsaved_automatic_pair_becomes_manual_from_snapshot(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $outward, $back));
        $input['trips'][1]['date'] = '2026-01-11';

        $route = $this->builder->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($route);

        self::assertCount(2, $logs);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertEquals($outward->id, $logs[0]->id);
        self::assertEquals($back->id, $logs[1]->id);
        self::assertSame('2026-01-11', $logs[1]->date->format('Y-m-d'));
    }

    public function test_free_edited_pair_ids_survive_repeated_fill_and_route_for_saving(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $expectedIds = [(string) $outward->id, (string) $back->id];
        $repository = $this->createMock(WaybillRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('hasExistingTripIds')->with(self::callback(
            static fn (StringCollection $ids): bool => [] === array_diff($expectedIds, $ids->getList()),
        ))->willReturn(false);
        $builder = new WaybillRouteBuilder($repository);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $outward, $back));
        $input['trips'][0]['distance'] = '12';
        $settings = new RouteSettings($waybill->vehicle()->initialLocation(), $outward->destination);
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $filler = new WaybillRouteFiller();
        $odometer = new OdometerRange(100, 142);
        $calendar = new ProductionCalendar();

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $fixed = $builder->buildFixed($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
            self::assertNotNull($fixed);
            $filled = $filler->fill($settings, $period, $fixed, $odometer, $calendar);
            self::assertTrue($filled->isSuccessful(), $filled->error() ?? '');
            $route = $filled->route();
            self::assertNotNull($route);
            $input = $mapper->withRoute($input, $route);
            $manualIds = array_values(array_map(
                static fn (array $row): string => $row['id'],
                array_filter($input['trips'], static fn (array $row): bool => RouteLogOrigin::Manual->value === $row['origin']),
            ));
            self::assertSame($expectedIds, $manualIds);
        }

        $savedRoute = $builder->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $waybill->setTrips(...$this->logs($savedRoute));
        $manualTrips = array_values(array_filter($waybill->trips(), static fn (RouteLog $trip): bool => RouteLogOrigin::Manual === $trip->origin));
        self::assertSame($expectedIds, array_map(static fn (RouteLog $trip): string => (string) $trip->id, $manualTrips));
    }

    public function test_fixed_route_keeps_edited_pair_and_drops_unedited_automatic_pair(): void
    {
        $waybill = $this->draft();
        [$editedOutward, $editedBack] = $this->pair($waybill->vehicle(), null);
        [$oldOutward, $oldBack] = $this->pair($waybill->vehicle(), $editedBack, '2026-01-12');
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $editedOutward, $oldBack));
        $input['trips'][0]['distance'] = '12';

        $route = $this->builder->buildFixed($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($route);

        self::assertCount(2, $logs);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertEquals($editedOutward->id, $logs[0]->id);
        self::assertEquals($editedBack->id, $logs[1]->id);
        self::assertSame(12, $logs[0]->distanceInKilometers());
        foreach ($logs as $log) {
            self::assertFalse($log->id->equals($oldOutward->id));
            self::assertFalse($log->id->equals($oldBack->id));
        }
    }

    public function test_repeated_fill_ignores_stale_dates_of_removed_automatic_trips(): void
    {
        $waybill = $this->draft();
        $inputs = $this->staleAutomaticInputs($waybill);

        $fixed = $this->builder->buildFixed($waybill, null, true, ...$inputs);
        $logs = $this->logs($fixed);

        self::assertCount(2, $logs);
        self::assertSame(['2026-01-29', '2026-01-29'], array_map(static fn (RouteLog $log): string => $log->date->format('Y-m-d'), $logs));
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertSame('Россия, База', $logs[0]->departure()->name);
        self::assertSame('Россия, Ручная', $logs[1]->departure()->name);
        self::assertSame($logs[0], $logs[1]->previous);

        $settings = new RouteSettings($waybill->vehicle()->initialLocation(), new Destination(new Location('Россия', 'Клиент'), 10));
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $filled = (new WaybillRouteFiller())->fill($settings, $period, $fixed, new OdometerRange(100, 140), new ProductionCalendar());

        self::assertTrue($filled->isSuccessful(), $filled->error() ?? '');
        $filledRoute = $filled->route();
        self::assertNotNull($filledRoute);
        $filledLogs = $this->logs($filledRoute);
        self::assertCount(4, $filledLogs);
        self::assertSame(40, $filledRoute->distance());
        self::assertCount(2, array_filter($filledLogs, static fn (RouteLog $log): bool => RouteLogOrigin::Automatic === $log->origin));
        $filledManual = array_values(array_filter($filledLogs, static fn (RouteLog $log): bool => RouteLogOrigin::Manual === $log->origin));
        self::assertCount(2, $filledManual);
        self::assertEquals($logs[0]->id, $filledManual[0]->id);
        self::assertEquals($logs[1]->id, $filledManual[1]->id);
        self::assertEquals($logs[0]->departure(), $filledManual[0]->departure());
        self::assertEquals($logs[1]->departure(), $filledManual[1]->departure());
        self::assertNotNull($inputs[2]->id);
        self::assertNotNull($inputs[3]->id);
        foreach ($filledLogs as $log) {
            self::assertFalse($log->id->equals($inputs[2]->id));
            self::assertFalse($log->id->equals($inputs[3]->id));
        }
    }

    public function test_saving_still_rejects_stale_automatic_dates(): void
    {
        $waybill = $this->draft();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Дата поездки не может быть раньше предыдущей.');
        $this->builder->build($waybill, null, true, ...$this->staleAutomaticInputs($waybill));
    }

    public function test_fixed_route_does_not_validate_dates_of_removed_automatic_pair(): void
    {
        $waybill = $this->draft();
        $pairId = Uuid::v7();
        $inputs = [
            $this->automaticInput('2024-12-31', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2024-12-30', new Destination($waybill->vehicle()->initialLocation(), 10), $pairId),
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Ручная'), 10)),
        ];

        $logs = $this->logs($this->builder->buildFixed($waybill, null, true, ...$inputs));

        self::assertCount(1, $logs);
        self::assertSame('Россия, База', $logs[0]->departure()->name);
        self::assertSame('2026-01-29', $logs[0]->date->format('Y-m-d'));
    }

    #[DataProvider('differentDepartureSnapshots')]
    public function test_fixed_route_rejects_implicit_change_of_manual_departure(Location $automaticReturn): void
    {
        $waybill = $this->draft();
        $pairId = Uuid::v7();
        $inputs = [
            $this->automaticInput('2026-01-23', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2026-01-23', new Destination($automaticReturn, 10), $pairId),
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Ручная'), 10)),
        ];

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Нельзя перестроить маршрут: изменится отправление ручной поездки.');
        $this->builder->buildFixed($waybill, null, true, ...$inputs);
    }

    /** @return iterable<string, array{Location}> */
    public static function differentDepartureSnapshots(): iterable
    {
        yield 'адрес' => [new Location('Россия', 'Другая база')];
        yield 'предприятие' => [new Location('Россия', 'База', enterprise: 'Другое предприятие')];
        yield 'координаты' => [new Location('Россия', 'База', latitude: 55.0, longitude: 37.0)];
        yield 'идентификатор' => [new Location('Россия', 'База', id: Uuid::v7())];
    }

    public function test_fixed_route_checks_the_departure_of_each_manual_trip(): void
    {
        $waybill = $this->draft();
        $pairId = Uuid::v7();
        $manualDestination = new Location('Россия', 'Ручная', enterprise: 'Первое предприятие');
        $automaticReturn = new Location('Россия', 'Ручная', enterprise: 'Другое предприятие');
        $inputs = [
            new TripInput(null, new \DateTimeImmutable('2026-01-20'), new Destination($manualDestination, 10)),
            $this->automaticInput('2026-01-23', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2026-01-23', new Destination($automaticReturn, 10), $pairId),
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Другая'), 10)),
        ];

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Нельзя перестроить маршрут: изменится отправление ручной поездки.');
        $this->builder->buildFixed($waybill, null, true, ...$inputs);
    }

    public function test_fixed_route_checks_address_even_when_departure_id_is_unchanged(): void
    {
        $waybill = $this->draft();
        $locationId = Uuid::v7();
        $departure = new Location('Россия', 'Другая база', id: $locationId);
        $previous = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2025-12-31'), new Destination($departure, 5), RouteLogOrigin::Manual);
        $pairId = Uuid::v7();
        $inputs = [
            $this->automaticInput('2026-01-23', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2026-01-23', new Destination(new Location('Россия', 'Подменённый адрес', id: $locationId), 10), $pairId),
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Ручная'), 10)),
        ];

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Нельзя перестроить маршрут: изменится отправление ручной поездки.');
        $this->builder->buildFixed($waybill, $previous, true, ...$inputs);
    }

    public function test_fixed_route_validates_chronology_of_retained_manual_trips(): void
    {
        $waybill = $this->draft();
        $pairId = Uuid::v7();
        $manualDestination = new Location('Россия', 'Ручная');
        $inputs = [
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination($manualDestination, 10)),
            $this->automaticInput('2026-01-29', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2026-01-29', new Destination($manualDestination, 10), $pairId),
            new TripInput(null, new \DateTimeImmutable('2026-01-28'), new Destination(new Location('Россия', 'Другая'), 10)),
        ];

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Дата поездки не может быть раньше предыдущей.');
        $this->builder->buildFixed($waybill, null, true, ...$inputs);
    }

    public function test_fixed_route_validates_manual_trip_against_predecessor_date(): void
    {
        $waybill = $this->draft();
        $previous = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2026-01-30'), new Destination(new Location('Россия', 'Другая база'), 5), RouteLogOrigin::Manual);
        $manual = new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Ручная'), 10));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Дата поездки не может быть раньше предыдущей.');
        $this->builder->buildFixed($waybill, $previous, true, $manual);
    }

    public function test_fixed_route_keeps_saved_manual_trip_with_tampered_automatic_origin(): void
    {
        $waybill = $this->draft();
        $manual = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Россия', 'Клиент'), 10), RouteLogOrigin::Manual);
        $back = new RouteLog(Uuid::v7(), $waybill->vehicle(), $manual, $manual->date, new Destination($waybill->vehicle()->initialLocation(), 10), RouteLogOrigin::Automatic);
        $waybill->setTrips($manual, $back);
        $pairId = Uuid::v7();
        $inputs = [
            new TripInput($manual->id, $manual->date, $manual->destination, RouteLogOrigin::Automatic, $pairId),
            new TripInput($back->id, $back->date, $back->destination, RouteLogOrigin::Automatic, $pairId),
        ];

        $logs = $this->logs($this->builder->buildFixed($waybill, null, true, ...$inputs));

        self::assertCount(1, $logs);
        self::assertEquals($manual->id, $logs[0]->id);
        self::assertSame(RouteLogOrigin::Manual, $logs[0]->origin);
        self::assertSame('Россия, База', $logs[0]->departure()->name);
    }

    public function test_fixed_route_uses_saved_automatic_snapshots_when_pair_is_edited(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $waybill->setTrips($outward, $back);
        $pairId = Uuid::v7();
        $inputs = [
            new TripInput($outward->id, $outward->date, new Destination($outward->destination->location, 12), RouteLogOrigin::Automatic, $pairId),
            new TripInput($back->id, $back->date, $back->destination, RouteLogOrigin::Automatic, $pairId),
        ];

        $logs = $this->logs($this->builder->buildFixed($waybill, null, true, ...$inputs));

        self::assertCount(2, $logs);
        self::assertEquals($outward->id, $logs[0]->id);
        self::assertEquals($back->id, $logs[1]->id);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertSame(12, $logs[0]->distanceInKilometers());
        self::assertSame($logs[0], $logs[1]->previous);
    }

    public function test_fixed_route_discards_unchanged_saved_automatic_pair_without_form_snapshots(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $waybill->setTrips($outward, $back);
        $pairId = Uuid::v7();
        $inputs = [
            new TripInput($outward->id, $outward->date, $outward->destination, RouteLogOrigin::Automatic, $pairId),
            new TripInput($back->id, $back->date, $back->destination, RouteLogOrigin::Automatic, $pairId),
        ];

        self::assertSame([], $this->logs($this->builder->buildFixed($waybill, null, true, ...$inputs)));
    }

    public function test_fixed_route_preserves_predecessor_after_removing_automatic_pair(): void
    {
        $waybill = $this->draft();
        $locationId = Uuid::v7();
        $location = new Location('Россия', 'Другая база', enterprise: 'Предприятие', latitude: 55.0, longitude: 37.0, id: $locationId);
        $matchingSnapshot = new Location('Россия', 'Другая база', enterprise: 'Предприятие', latitude: 55.0, longitude: 37.0, id: Uuid::fromString((string) $locationId));
        $previous = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2025-12-31'), new Destination($location, 5), RouteLogOrigin::Manual);
        $pairId = Uuid::v7();
        $automatic = [
            $this->automaticInput('2026-01-23', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2026-01-23', new Destination($matchingSnapshot, 10), $pairId),
        ];
        $manual = new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Ручная'), 10));
        $builder = $this->builder;

        $empty = $builder->buildFixed($waybill, $previous, true, ...$automatic);
        $fixed = $builder->buildFixed($waybill, $previous, true, ...[...$automatic, $manual]);

        self::assertSame($previous, $empty?->predecessor());
        self::assertSame([], $this->logs($empty));
        $logs = $this->logs($fixed);
        self::assertCount(1, $logs);
        self::assertSame($previous, $logs[0]->previous);
        self::assertSame($location, $logs[0]->departure());
    }

    public function test_fixed_route_rejects_unknown_manual_uuid(): void
    {
        $waybill = $this->draft();
        $input = new TripInput(Uuid::v7(), new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Россия', 'Чужая'), 10));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Поездка не принадлежит этому черновику.');
        $this->builder->buildFixed($waybill, null, true, $input);
    }

    public function test_fixed_route_rejects_missing_automatic_snapshot_even_if_pair_would_be_removed(): void
    {
        $waybill = $this->draft();
        $pairId = Uuid::v7();
        $input = new TripInput(Uuid::v7(), new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Россия', 'Клиент'), 10), RouteLogOrigin::Automatic, $pairId);
        $back = $this->automaticInput('2026-01-10', new Destination($waybill->vehicle()->initialLocation(), 10), $pairId);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Исходные данные автоматической поездки не переданы.');
        $this->builder->buildFixed($waybill, null, true, $input, $back);
    }

    public function test_unchanged_automatic_pair_remains_automatic(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $outward, $back));

        $route = $this->builder->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($route);

        self::assertSame([RouteLogOrigin::Automatic, RouteLogOrigin::Automatic], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertEquals($outward->id, $logs[0]->id);
        self::assertEquals($back->id, $logs[1]->id);
    }

    public function test_new_manual_preview_id_is_cleared_before_save(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $builder = $this->builder;
        $input = $mapper->input($waybill, 1);
        $input['trips'] = [array_merge($mapper->blankTrip(), ['date' => '2026-01-10', 'city' => 'Ручная', 'distance' => '10'])];
        $fixed = $builder->buildFixed($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);

        $preview = $mapper->withRoute($input, $fixed);

        self::assertSame('', $preview['trips'][0]['id']);
        $route = $builder->build($waybill, null, true, ...$mapper->updateCommand($waybill, $preview)->trips);
        self::assertSame('Россия, Ручная', $this->logs($route)[0]->destination->location->name);
        self::assertSame('База', $preview['initialLocation']['city']);
    }

    public function test_unknown_manual_uuid_is_still_rejected(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['trips'] = [array_merge($mapper->blankTrip(), ['id' => (string) Uuid::v7(), 'date' => '2026-01-10', 'city' => 'Чужая', 'distance' => '10'])];

        $this->expectExceptionMessage('Поездка не принадлежит этому черновику.');
        $this->builder->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
    }

    public function test_empty_fixed_route_keeps_predecessor_and_form_start(): void
    {
        $waybill = $this->draft();
        $previous = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2025-12-31'), new Destination(new Location('Россия', 'Фактическая'), 5), RouteLogOrigin::Manual);

        $route = $this->builder->buildFixed($waybill, $previous, true);
        $input = (new WaybillFormMapper(new LocationFormMapper()))->input($waybill, 1, $previous);

        self::assertSame($previous, $route?->predecessor());
        self::assertSame([], $this->logs($route));
        self::assertSame('Фактическая', $input['initialLocation']['city']);
    }

    public function test_manual_trip_inside_automatic_pair_preserves_the_whole_chain_on_repeated_fill(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $builder = $this->builder;
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $outward, $back));
        $manual = array_merge($mapper->blankTrip(), ['date' => '2026-01-10', 'city' => 'В', 'distance' => '5']);
        array_splice($input['trips'], 1, 0, [$manual]);

        $fixed = $builder->buildFixed($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($fixed);

        self::assertCount(3, $logs);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertSame('Россия, Клиент', $logs[1]->departure()->name);
        self::assertSame('Россия, В', $logs[1]->destination->location->name);
        self::assertSame('Россия, В', $logs[2]->departure()->name);

        $settings = new RouteSettings(new Location('Россия', 'База'), new Destination(new Location('Россия', 'Клиент'), 20));
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $filled = (new WaybillRouteFiller())->fill($settings, $period, $fixed, new OdometerRange(100, 165), new ProductionCalendar());
        self::assertTrue($filled->isSuccessful(), $filled->error() ?? '');
        self::assertCount(5, $this->logs($filled->route()));

        $preview = $mapper->withRoute($input, $filled->route());
        $inserted = array_values(array_filter($preview['trips'], static fn (array $trip): bool => 'В' === $trip['city']));
        self::assertSame('', $inserted[0]['id']);
        $repeated = $builder->buildFixed($waybill, null, true, ...$mapper->updateCommand($waybill, $preview)->trips);
        $repeatedLogs = $this->logs($repeated);
        self::assertCount(3, $repeatedLogs);
        self::assertSame('Россия, Клиент', $repeatedLogs[1]->departure()->name);
        self::assertSame('Россия, В', $repeatedLogs[2]->departure()->name);
        $refilled = (new WaybillRouteFiller())->fill($settings, $period, $repeated, new OdometerRange(100, 165), new ProductionCalendar());
        self::assertTrue($refilled->isSuccessful(), $refilled->error() ?? '');
        self::assertCount(5, $this->logs($refilled->route()));
    }

    public function test_empty_fixed_route_does_not_move_vehicle_back_to_initial_base_after_departure(): void
    {
        $waybill = $this->draft();
        $previous = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2025-12-31'), new Destination(new Location('Россия', 'Не база'), 5), RouteLogOrigin::Manual);
        $fixed = $this->builder->buildFixed($waybill, $previous, true);
        $settings = new RouteSettings(new Location('Россия', 'База'), new Destination(new Location('Россия', 'Клиент'), 10));

        $result = (new WaybillRouteFiller())->fill(
            $settings,
            WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')),
            $fixed,
            new OdometerRange(100, 120),
            new ProductionCalendar(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertSame('Нет доступных участков нахождения на базе.', $result->error());
    }

    public function test_empty_fixed_route_uses_base_reached_after_initial_location(): void
    {
        $waybill = $this->draft('Старт');
        $previous = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2025-12-31'), new Destination(new Location('Россия', 'База'), 5), RouteLogOrigin::Manual);
        $fixed = $this->builder->buildFixed($waybill, $previous, true);
        $settings = new RouteSettings(new Location('Россия', 'База'), new Destination(new Location('Россия', 'Клиент'), 10));

        $result = (new WaybillRouteFiller())->fill(
            $settings,
            WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')),
            $fixed,
            new OdometerRange(100, 120),
            new ProductionCalendar(),
        );

        self::assertTrue($result->isSuccessful(), $result->error() ?? '');
        $first = $this->logs($result->route())[0];
        self::assertSame($previous, $first->previous);
        self::assertSame('Россия, База', $first->departure()->name);
    }

    /** @return list<TripInput> */
    private function staleAutomaticInputs(Waybill $waybill): array
    {
        $pairId = Uuid::v7();
        $base = $waybill->vehicle()->initialLocation();

        return [
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination(new Location('Россия', 'Ручная'), 10)),
            new TripInput(null, new \DateTimeImmutable('2026-01-29'), new Destination($base, 10)),
            $this->automaticInput('2026-01-23', new Destination(new Location('Россия', 'Клиент'), 10), $pairId),
            $this->automaticInput('2026-01-23', new Destination($base, 10), $pairId),
        ];
    }

    private function automaticInput(string $date, Destination $destination, Uuid $pairId): TripInput
    {
        $date = new \DateTimeImmutable($date);

        return new TripInput(Uuid::v7(), $date, $destination, RouteLogOrigin::Automatic, $pairId, $date, $destination);
    }

    /** @return array{RouteLog, RouteLog} */
    private function pair(Vehicle $vehicle, ?RouteLog $previous, string $date = '2026-01-10'): array
    {
        $outward = new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable($date), new Destination(new Location('Россия', 'Клиент'), 10), RouteLogOrigin::Automatic);
        $back = new RouteLog(Uuid::v7(), $vehicle, $outward, new \DateTimeImmutable($date), new Destination($vehicle->initialLocation(), 10), RouteLogOrigin::Automatic);

        return [$outward, $back];
    }

    private function draft(string $initialLocation = 'База'): Waybill
    {
        $base = new Location('Россия', $initialLocation);
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 100, BigDecimal::of('30'));
        $vehicle->enter(new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), $base));

        return Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
    }

    /** @return list<RouteLog> */
    private function logs(?Route $route): array
    {
        self::assertNotNull($route);

        return iterator_to_array($route->logs(), false);
    }
}
