<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class LocationTest extends TestCase
{
    public function test_country_defaults_to_russia_but_explicit_values_are_preserved(): void
    {
        self::assertSame('Россия', (new Location())->country);
        self::assertSame('Россия, Москва', (new Location(city: 'Москва'))->toString());
        self::assertSame('Беларусь', (new Location('Беларусь', 'Минск'))->country);
        self::assertSame('', (new Location('', 'Минск'))->country);
    }

    public function test_compares_locations_by_trimmed_name(): void
    {
        $location = new Location(' Склад ');

        self::assertSame('Склад', $location->name);
        self::assertSame('Склад', $location->toString());
        self::assertNull($location->latitude);
        self::assertNull($location->longitude);
        self::assertTrue($location->equals(new Location('Склад')));
        self::assertFalse($location->equals(new Location('Офис')));
    }

    public function test_rejects_blank_name(): void
    {
        $this->expectException(AppException::class);
        new Location(" \t\n");
    }

    public function test_formats_structured_address_for_the_document(): void
    {
        $location = new Location(' Россия ', ' Москва ', ' Тверская ', ' 1А ');
        self::assertSame('Россия, Москва, Тверская, 1А', $location->toString());
        self::assertSame('Россия', $location->country);
        self::assertSame('Москва', $location->city?->name);
        self::assertSame('Тверская', $location->street);
        self::assertSame('1А', $location->house);
        self::assertTrue($location->equals(Location::fromArray($location->jsonSerialize())));
    }

    public function test_compares_address_parts_instead_of_formatted_text(): void
    {
        $first = new Location('Россия, Москва', 'Тверская');
        $second = new Location('Россия', 'Москва', 'Тверская');
        self::assertSame($first->toString(), $second->toString());
        self::assertFalse($first->equals($second));
    }

    public function test_identity_enterprise_and_coordinates_round_trip(): void
    {
        $id = Uuid::v7();
        $location = new Location('Россия', 'Екатеринбург', 'Ирбитская', '8', '  Предприятие  ', 56.8, 60.6, $id);

        $restored = Location::fromArray($location->jsonSerialize());
        self::assertSame((string) $id, (string) $restored->id);
        self::assertSame('Предприятие', $restored->enterprise);
        self::assertSame(56.8, $restored->latitude);
        self::assertSame(60.6, $restored->longitude);
        self::assertSame('Россия, Екатеринбург, Ирбитская, 8, Предприятие', $restored->title());
        self::assertTrue($restored->samePlace(new Location('Россия', 'Екаетринбург', 'Ирбитская', '8', id: $id)));
        self::assertFalse($restored->samePlace(new Location('Россия', 'Екатеринбург', 'Ирбитская', '8', id: Uuid::v7())));
    }

    public function test_old_snapshot_without_identity_uses_exact_address_comparison(): void
    {
        $location = Location::fromArray(['country' => 'Россия', 'city' => 'Екатеринбург', 'street' => '', 'house' => '']);

        self::assertNull($location->id);
        self::assertTrue($location->samePlace(new Location('Россия', 'Екатеринбург')));
        self::assertFalse($location->samePlace(new Location('Россия', 'Екаетринбург')));
    }

    public function test_restores_coordinates_only_for_the_same_unchanged_address(): void
    {
        $id = Uuid::v7();
        $original = new Location('Россия', 'Москва', 'Лесная', '1', 'Склад', 55.7, 37.6, $id);
        $unchanged = new Location('Россия', 'Москва', 'Лесная', '1', 'Другой склад', id: $id);

        $restored = $unchanged->withCoordinatesFromUnchangedAddress($original);

        self::assertSame(55.7, $restored->latitude);
        self::assertSame(37.6, $restored->longitude);
        self::assertSame('Другой склад', $restored->enterprise);
        self::assertNull((new Location('Россия', 'Москва', 'Лесная', '2', id: $id))->withCoordinatesFromUnchangedAddress($original)->latitude);
        self::assertNull((new Location('Россия', 'Москва', 'Лесная', '1', id: Uuid::v7()))->withCoordinatesFromUnchangedAddress($original)->latitude);
        self::assertNull((new Location('Россия', 'Москва', 'Лесная', '1'))->withCoordinatesFromUnchangedAddress($original)->latitude);
    }

    public function test_preserves_explicit_coordinates_and_supports_old_snapshots_without_ids(): void
    {
        $original = new Location('Россия', 'Москва', latitude: 55.7, longitude: 37.6);
        $unchanged = new Location('Россия', 'Москва');
        $explicit = new Location('Россия', 'Москва', latitude: 55.8, longitude: 37.7);

        self::assertSame(55.7, $unchanged->withCoordinatesFromUnchangedAddress($original)->latitude);
        self::assertSame(55.8, $explicit->withCoordinatesFromUnchangedAddress($original)->latitude);
    }

    public function test_rejects_incomplete_coordinates(): void
    {
        $this->expectException(AppException::class);
        new Location('База', latitude: 56.8);
    }

    #[DataProvider('invalidCoordinates')]
    public function test_rejects_out_of_range_coordinates(float $latitude, float $longitude): void
    {
        $this->expectException(AppException::class);
        new Location('База', latitude: $latitude, longitude: $longitude);
    }

    /** @return iterable<string, array{float, float}> */
    public static function invalidCoordinates(): iterable
    {
        yield 'latitude below minimum' => [-90.1, 60.6];
        yield 'latitude above maximum' => [90.1, 60.6];
        yield 'longitude below minimum' => [56.8, -180.1];
        yield 'longitude above maximum' => [56.8, 180.1];
    }

    public function test_rejects_unicode_whitespace_only(): void
    {
        $this->expectException(AppException::class);
        new Location("\u{00A0}");
    }
}
