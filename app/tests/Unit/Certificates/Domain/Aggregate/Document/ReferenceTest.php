<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Domain\Aggregate\Document;

use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ReferenceTest extends TestCase
{
    public function test_equals_by_type_and_id(): void
    {
        $id = Uuid::v7();
        $a = new Reference(ReferenceType::CoatingSystem, $id);
        $b = new Reference(ReferenceType::CoatingSystem, Uuid::fromString((string) $id));

        self::assertTrue($a->equals($b));
    }

    public function test_not_equal_when_id_differs(): void
    {
        $a = new Reference(ReferenceType::CoatingSystem, Uuid::v7());
        $b = new Reference(ReferenceType::CoatingSystem, Uuid::v7());

        self::assertFalse($a->equals($b));
    }

    public function test_not_equal_when_type_differs(): void
    {
        $id = Uuid::v7();
        $a = new Reference(ReferenceType::CoatingSystem, $id);
        $b = new Reference(ReferenceType::Coating, $id);

        self::assertFalse($a->equals($b));
    }

    public function test_json_round_trip(): void
    {
        $ref = new Reference(ReferenceType::Coating, Uuid::v7());
        $restored = Reference::fromArray($ref->jsonSerialize());

        self::assertTrue($ref->equals($restored));
        self::assertSame(['type', 'id'], array_keys($ref->jsonSerialize()));
    }
}
