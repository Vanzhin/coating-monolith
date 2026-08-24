<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Iso12944\Iso12944Match;
use PHPUnit\Framework\TestCase;

final class Iso12944MatchTest extends TestCase
{
    public function test_round_trip_json(): void
    {
        $m = new Iso12944Match(ComplianceStandard::ISO_12944, 'C4', 'HIGH');
        self::assertSame(['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'], $m->jsonSerialize());
        $restored = Iso12944Match::fromArray($m->jsonSerialize());
        self::assertEquals($m, $restored);
    }
}
