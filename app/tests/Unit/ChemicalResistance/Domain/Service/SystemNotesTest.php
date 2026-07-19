<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Domain\Service;

use App\ChemicalResistance\Domain\Service\SystemNotes;
use PHPUnit\Framework\TestCase;

final class SystemNotesTest extends TestCase
{
    public function test_contains_high_viscosity_rule(): void
    {
        $notes = SystemNotes::all();
        self::assertNotEmpty($notes);
        $found = false;
        foreach ($notes as $n) {
            if (str_contains($n->description, '+70')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'System notes must contain the "+70°C for solids" rule from legend.');
    }
}
