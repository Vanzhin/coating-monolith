<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\CadenceKind;
use PHPUnit\Framework\TestCase;

final class CadenceKindTest extends TestCase
{
    public function test_requires_number_only_for_periodic(): void
    {
        self::assertTrue(CadenceKind::Periodic->requiresNumber());
        self::assertFalse(CadenceKind::Once->requiresNumber());
        self::assertFalse(CadenceKind::ByFact->requiresNumber());
        self::assertFalse(CadenceKind::ByManufacturerDoc->requiresNumber());
    }

    public function test_periodic_is_the_only_computed_kind(): void
    {
        self::assertTrue(CadenceKind::Periodic->isPeriodic());
        self::assertFalse(CadenceKind::Once->isPeriodic());
        self::assertFalse(CadenceKind::ByFact->isPeriodic());
        self::assertFalse(CadenceKind::ByManufacturerDoc->isPeriodic());
    }

    public function test_four_kinds_with_titles(): void
    {
        self::assertCount(4, CadenceKind::cases());
        foreach (CadenceKind::cases() as $kind) {
            self::assertNotSame('', $kind->title());
        }
    }
}
