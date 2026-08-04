<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceMatch;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use PHPUnit\Framework\TestCase;

final class ComplianceMatchTest extends TestCase
{
    public function test_round_trip_json(): void
    {
        $m = new ComplianceMatch(ComplianceStandard::ISO_12944, 'C4', 'HIGH');
        self::assertSame(['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'], $m->jsonSerialize());
        $restored = ComplianceMatch::fromArray($m->jsonSerialize());
        self::assertEquals($m, $restored);
    }
}
