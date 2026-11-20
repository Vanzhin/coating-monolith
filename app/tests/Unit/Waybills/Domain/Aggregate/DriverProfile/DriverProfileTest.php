<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DriverProfileTest extends TestCase
{
    public function test_driver_profile_contains_details_and_route_settings(): void
    {
        $id = Uuid::v7();
        $details = $this->details();
        $base = new Location('База');
        $destination = new Destination(new Location('Точка А'), 14);
        $profile = new DriverProfile($id, 'owner', $details, $base, $destination);

        self::assertSame((string) $id, $profile->getId());
        self::assertSame('owner', $profile->getOwnerId());
        self::assertTrue($profile->isOwnedBy('owner'));
        self::assertFalse($profile->isOwnedBy('another'));
        self::assertSame($details, $profile->getDriverDetails());
        self::assertSame($base, $profile->getBase());
        self::assertSame([$destination], $profile->getDestinations());
    }

    public function test_rejects_empty_owner(): void
    {
        $this->expectException(AppException::class);
        new DriverProfile(Uuid::v7(), ' ', $this->details(), new Location('База'));
    }

    public function test_destination_cannot_be_the_base(): void
    {
        $profile = $this->profile();
        $this->expectException(AppException::class);
        $profile->replaceDestinations(new Destination(new Location('База'), 1));
    }

    public function test_changing_base_to_a_destination_is_rejected_without_mutation(): void
    {
        $profile = $this->profile();
        $destination = new Destination(new Location('Точка А'), 14);
        $profile->replaceDestinations($destination);

        try {
            $profile->changeBase(new Location('Точка А'));
            self::fail('База не должна совпадать с направлением.');
        } catch (AppException) {
            self::assertSame('База', $profile->getBase()->name);
            self::assertSame([$destination], $profile->getDestinations());
        }
    }

    private function profile(): DriverProfile
    {
        return new DriverProfile(Uuid::v7(), 'owner', $this->details(), new Location('База'));
    }

    private function details(): DriverDetails
    {
        return new DriverDetails('Иванов Иван', '00 00 000000', new \DateTimeImmutable('2020-01-01'), '000-000-000 00');
    }
}
