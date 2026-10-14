<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\MaterialMode;
use PHPUnit\Framework\TestCase;

final class MaterialModeTest extends TestCase
{
    public function test_values_and_titles(): void
    {
        self::assertSame('inventoried', MaterialMode::Inventoried->value);
        self::assertSame('consumable', MaterialMode::Consumable->value);
        self::assertSame('инвентарная', MaterialMode::Inventoried->title());
        self::assertSame('расходная', MaterialMode::Consumable->title());
    }

    public function test_consumable_predicate_and_writeoff_applicability(): void
    {
        self::assertFalse(MaterialMode::Inventoried->isConsumable());
        self::assertTrue(MaterialMode::Inventoried->writeOffApplicable());
        self::assertTrue(MaterialMode::Consumable->isConsumable());
        self::assertFalse(MaterialMode::Consumable->writeOffApplicable());
    }
}
