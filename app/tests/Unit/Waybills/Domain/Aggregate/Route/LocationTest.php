<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('Москва', $location->city);
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

    public function test_rejects_unicode_whitespace_only(): void
    {
        $this->expectException(AppException::class);
        new Location("\u{00A0}");
    }
}
