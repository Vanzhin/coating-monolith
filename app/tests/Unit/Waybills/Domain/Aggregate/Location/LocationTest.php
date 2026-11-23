<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Location;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Location\Location;
use App\Waybills\Domain\Aggregate\Route\Location as LocationSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class LocationTest extends TestCase
{
    public function test_stores_owner_snapshot_and_modification_dates(): void
    {
        $id = Uuid::v7();
        $createdAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2026-01-02 11:00:00');
        $location = new Location($id, 'owner', new LocationSnapshot('Россия', 'Екаетринбург', enterprise: 'Склад', latitude: 56.8, longitude: 60.6), $createdAt);

        $location->change(new LocationSnapshot('Россия', 'Екатеринбург', enterprise: 'Склад', latitude: 56.8, longitude: 60.6), $updatedAt);
        $snapshot = $location->snapshot();

        self::assertSame((string) $id, $location->getId());
        self::assertTrue($location->isOwnedBy('owner'));
        self::assertFalse($location->isOwnedBy('other'));
        self::assertSame($createdAt, $location->createdAt());
        self::assertSame($updatedAt, $location->updatedAt());
        self::assertSame($id, $snapshot->id);
        self::assertSame('Екатеринбург', $snapshot->city?->name);
        self::assertSame('Склад', $snapshot->enterprise);
        self::assertSame(56.8, $snapshot->latitude);
        self::assertSame(60.6, $snapshot->longitude);
    }

    public function test_rejects_blank_owner(): void
    {
        $this->expectException(AppException::class);
        new Location(Uuid::v7(), ' ', new LocationSnapshot('База'), new \DateTimeImmutable());
    }
}
