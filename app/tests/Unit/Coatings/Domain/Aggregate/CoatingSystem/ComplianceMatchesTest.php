<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceMatch;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceMatches;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use PHPUnit\Framework\TestCase;

final class ComplianceMatchesTest extends TestCase
{
    public function test_collects_matches_and_serializes(): void
    {
        $matches = new ComplianceMatches();
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C4', 'HIGH'));
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C3', 'MEDIUM'));

        self::assertCount(2, $matches);
        self::assertCount(2, iterator_to_array($matches));
        self::assertSame([
            ['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'HIGH'],
            ['standard' => 'ISO_12944', 'category' => 'C3', 'durability' => 'MEDIUM'],
        ], $matches->jsonSerialize());
    }
}
