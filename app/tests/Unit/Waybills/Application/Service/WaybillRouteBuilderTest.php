<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Application\Service;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Infrastructure\Controller\Waybill\WaybillFormMapper;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRouteBuilderTest extends TestCase
{
    public function test_saved_automatic_pair_becomes_manual_after_edit(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $waybill->setTrips($outward, $back);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['trips'][0]['distance'] = '11';

        $route = (new WaybillRouteBuilder())->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
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

        $route = (new WaybillRouteBuilder())->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($route);

        self::assertCount(2, $logs);
        self::assertSame([RouteLogOrigin::Manual, RouteLogOrigin::Manual], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
        self::assertEquals($outward->id, $logs[0]->id);
        self::assertEquals($back->id, $logs[1]->id);
        self::assertSame('2026-01-11', $logs[1]->date->format('Y-m-d'));
    }

    public function test_fixed_route_keeps_edited_pair_and_drops_unedited_automatic_pair(): void
    {
        $waybill = $this->draft();
        [$editedOutward, $editedBack] = $this->pair($waybill->vehicle(), null);
        [$oldOutward, $oldBack] = $this->pair($waybill->vehicle(), $editedBack, '2026-01-12');
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $editedOutward, $oldBack));
        $input['trips'][0]['distance'] = '12';

        $route = (new WaybillRouteBuilder())->buildFixed($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
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

    public function test_unchanged_automatic_pair_remains_automatic(): void
    {
        $waybill = $this->draft();
        [$outward, $back] = $this->pair($waybill->vehicle(), null);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->withRoute($mapper->input($waybill, 1), new Route($waybill->vehicle(), $outward, $back));

        $route = (new WaybillRouteBuilder())->build($waybill, null, true, ...$mapper->updateCommand($waybill, $input)->trips);
        $logs = $this->logs($route);

        self::assertSame([RouteLogOrigin::Automatic, RouteLogOrigin::Automatic], array_map(static fn (RouteLog $log): RouteLogOrigin => $log->origin, $logs));
    }

    /** @return array{RouteLog, RouteLog} */
    private function pair(Vehicle $vehicle, ?RouteLog $previous, string $date = '2026-01-10'): array
    {
        $outward = new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable($date), new Destination(new Location('Россия', 'Клиент'), 10), RouteLogOrigin::Automatic);
        $back = new RouteLog(Uuid::v7(), $vehicle, $outward, new \DateTimeImmutable($date), new Destination($vehicle->initialLocation(), 10), RouteLogOrigin::Automatic);

        return [$outward, $back];
    }

    private function draft(): Waybill
    {
        $base = new Location('Россия', 'База');
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
