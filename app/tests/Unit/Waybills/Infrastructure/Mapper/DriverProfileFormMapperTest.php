<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Mapper;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\UseCase\Query\GetOwnDriverProfile\GetOwnDriverProfileQueryResult;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Infrastructure\Mapper\DriverProfileFormMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DriverProfileFormMapperTest extends TestCase
{
    public function test_new_profile_uses_default_country_and_respects_explicit_country(): void
    {
        $profile = new GetOwnDriverProfileQueryResult(null, null, null);
        $mapper = new DriverProfileFormMapper();
        $data = $mapper->fromProfile($profile);
        self::assertSame('Россия', $data['base']['country']);

        $data['fullName'] = 'Водитель';
        $data['licenseNumber'] = '1234567890';
        $data['licenseIssuedAt'] = '2020-01-01';
        $data['snils'] = '11223344595';
        $data['base'] = ['city' => 'Москва'];
        [$details, $route] = $mapper->toDomain($data, $profile);
        self::assertSame('Россия', $route->base->country);

        $data['base'] = ['country' => '', 'city' => 'Минск'];
        [$details, $route] = $mapper->toDomain($data, $profile);
        self::assertSame('', $route->base->country);

        $data['base']['country'] = 'Беларусь';
        [$details, $route] = $mapper->toDomain($data, $profile);
        self::assertSame('Беларусь', $route->base->country);
    }

    public function test_profile_round_trip_preserves_date_and_route(): void
    {
        $date = new \DateTimeImmutable('2020-02-01T12:34:56.123456+03:00');
        $profile = new GetOwnDriverProfileQueryResult(
            (string) Uuid::v7(), new DriverDetails('Водитель', '1234567890', $date, '11223344595'),
            new RouteSettings(new Location('Россия', 'Москва', 'Лесная', '1'), new Destination(new Location('Россия', 'Москва', 'Складская', '2'), 10, 'Склад')),
        );
        $mapper = new DriverProfileFormMapper();
        $data = $mapper->fromProfile($profile);
        self::assertSame('12 34 567890', $data['licenseNumber']);
        self::assertSame('112-233-445 95', $data['snils']);
        // HTTP sends numbers as strings.
        $data['destinations'][0]['distance'] = ' 10 ';
        $data['destinations'][0]['enterprise'] = '  Склад  ';
        [$details, $route] = $mapper->toDomain($data, $profile);
        self::assertSame($date, $details->licenseIssuedAt);
        self::assertSame('Водитель', $details->fullName);
        self::assertArrayNotHasKey('vehicleId', $data);
        self::assertSame('Россия, Москва, Лесная, 1', $route->base->toString());
        self::assertSame('Россия, Москва, Складская, 2', $route->destinations()[0]->location->toString());
        self::assertSame('Москва', $route->base->city);
        self::assertSame('2', $route->destinations()[0]->location->house);
        self::assertSame(10, $route->destinations()[0]->distance);
        self::assertSame('Склад', $route->destinations()[0]->enterprise);
        $data['destinations'][0]['enterprise'] = '  ';
        [, $route] = $mapper->toDomain($data, $profile);
        self::assertNull($route->destinations()[0]->enterprise);
        $data['destinations'][0]['distance'] = 'not a number';
        $this->expectException(AppException::class);
        $mapper->toDomain($data, $profile);
    }

    public function test_fractional_distance_is_rejected_without_rounding(): void
    {
        $mapper = new DriverProfileFormMapper();
        $profile = new GetOwnDriverProfileQueryResult(null, null, null);
        $data = [
            'licenseIssuedAt' => '2020-01-01',
            'destinations' => [['distance' => '10,5']],
        ];

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('целое число километров');
        $mapper->toDomain($data, $profile);
    }
}
