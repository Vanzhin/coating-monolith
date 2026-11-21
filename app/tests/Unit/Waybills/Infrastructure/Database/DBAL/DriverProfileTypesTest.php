<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Database\DBAL;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Infrastructure\Database\DBAL\DriverDetailsType;
use App\Waybills\Infrastructure\Database\DBAL\RouteSettingsType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class DriverProfileTypesTest extends TestCase
{
    public function test_snils_is_stored_normalized_and_restored_from_existing_formatted_value(): void
    {
        $type = new DriverDetailsType();
        $platform = new PostgreSQLPlatform();
        $details = new DriverDetails('Водитель', '77 аа 123456', new \DateTimeImmutable('2020-01-01'), '112-233-445 95');

        $stored = json_decode((string) $type->convertToDatabaseValue($details, $platform), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('77AA123456', $stored['licenseNumber']);
        self::assertSame('11223344595', $stored['snils']);
        $stored['snils'] = '112-233-445 95';
        $stored['licenseNumber'] = '77 АА 123456';
        $restored = $type->convertToPHPValue(json_encode($stored, JSON_THROW_ON_ERROR), $platform);
        self::assertNotNull($restored);
        self::assertSame('77AA123456', $restored->licenseNumber->value());
        self::assertSame('11223344595', $restored->snils->value());
        self::assertSame('112-233-445 95', $restored->snils->formatted());
    }

    public function test_details_are_validated_on_hydration(): void
    {
        $this->expectException(AppException::class);
        (new DriverDetailsType())->convertToPHPValue('{"fullName":" ","licenseNumber":"123","licenseIssuedAt":"2020-01-01T00:00:00.000000+00:00","snils":"123"}', new PostgreSQLPlatform());
    }

    public function test_invalid_existing_license_is_rejected_on_hydration(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('водительского удостоверения');
        (new DriverDetailsType())->convertToPHPValue('{"fullName":"Водитель","licenseNumber":"1234","licenseIssuedAt":"2020-01-01T00:00:00.000000+00:00","snils":"11223344595"}', new PostgreSQLPlatform());
    }

    public function test_invalid_license_date_is_rejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new DriverDetailsType())->convertToPHPValue('{"fullName":"Водитель","licenseNumber":"123","licenseIssuedAt":"","snils":"123"}', new PostgreSQLPlatform());
    }

    public function test_driver_license_date_preserves_named_timezone_and_microseconds(): void
    {
        $type = new DriverDetailsType();
        $platform = new PostgreSQLPlatform();
        $date = new \DateTimeImmutable('2020-07-01 12:30:45.123456', new \DateTimeZone('Europe/Berlin'));
        $details = new DriverDetails('Водитель', '1234567890', $date, '11223344595');

        $restored = $type->convertToPHPValue($type->convertToDatabaseValue($details, $platform), $platform);
        self::assertNotNull($restored);
        self::assertSame($date->format('U.uP'), $restored->licenseIssuedAt->format('U.uP'));
        self::assertSame('Europe/Berlin', $restored->licenseIssuedAt->getTimezone()->getName());
    }

    public function test_legacy_driver_license_date_remains_readable(): void
    {
        $json = '{"fullName":"Водитель","licenseNumber":"1234567890","licenseIssuedAt":"2020-01-01T12:30:45.123456+03:00","snils":"11223344595"}';
        $restored = (new DriverDetailsType())->convertToPHPValue($json, new PostgreSQLPlatform());

        self::assertNotNull($restored);
        self::assertSame('2020-01-01T12:30:45.123456+03:00', $restored->licenseIssuedAt->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_route_is_validated_on_hydration(): void
    {
        $this->expectException(AppException::class);
        (new RouteSettingsType())->convertToPHPValue('{"base":{"country":"База","city":"","street":"","house":""},"destinations":[{"location":{"country":"База","city":"","street":"","house":""},"distance":10,"enterprise":null}]}', new PostgreSQLPlatform());
    }

    public function test_distance_is_validated_on_hydration(): void
    {
        $this->expectException(AppException::class);
        (new RouteSettingsType())->convertToPHPValue('{"base":{"country":"База","city":"","street":"","house":""},"destinations":[{"location":{"country":"Клиент","city":"","street":"","house":""},"distance":0,"enterprise":null}]}', new PostgreSQLPlatform());
    }

    public function test_route_round_trip_stores_integer_distance_and_nullable_enterprise(): void
    {
        $type = new RouteSettingsType();
        $platform = new PostgreSQLPlatform();
        $route = new RouteSettings(
            new Location('База'),
            new Destination(new Location('Клиент'), 10, 'Предприятие'),
            new Destination(new Location('Другой клиент'), 1),
        );

        $json = $type->convertToDatabaseValue($route, $platform);
        $stored = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(10, $stored['destinations'][0]['distance']);
        self::assertSame('Предприятие', $stored['destinations'][0]['enterprise']);
        self::assertNull($stored['destinations'][1]['enterprise']);
        $restored = $type->convertToPHPValue($json, $platform);
        self::assertNotNull($restored);
        self::assertSame(10, $restored->destinations()[0]->distance);
        self::assertSame('Предприятие', $restored->destinations()[0]->enterprise);
        self::assertNull($restored->destinations()[1]->enterprise);
    }

    public function test_route_rejects_non_numeric_distance_in_json(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new RouteSettingsType())->convertToPHPValue('{"base":{"country":"База","city":"","street":"","house":""},"destinations":[{"location":{"country":"Клиент","city":"","street":"","house":""},"distance":"1.5","enterprise":null}]}', new PostgreSQLPlatform());
    }

    public function test_route_rejects_fractional_distance_in_json(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new RouteSettingsType())->convertToPHPValue('{"base":{"country":"База","city":"","street":"","house":""},"destinations":[{"location":{"country":"Клиент","city":"","street":"","house":""},"distance":1.5,"enterprise":null}]}', new PostgreSQLPlatform());
    }
}
