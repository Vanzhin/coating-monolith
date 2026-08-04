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

    public function test_strongest_only_keeps_only_dominating_pairs_within_atmospheric_family(): void
    {
        $matches = new ComplianceMatches();
        foreach (['C2', 'C3', 'C4', 'C5'] as $category) {
            foreach (['LOW', 'MEDIUM', 'HIGH', 'VERY_HIGH'] as $durability) {
                $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, $category, $durability));
            }
        }

        $result = $matches->strongestOnly();

        self::assertSame([
            ['standard' => 'ISO_12944', 'category' => 'C5', 'durability' => 'VERY_HIGH'],
        ], $result->jsonSerialize());
    }

    public function test_strongest_only_keeps_incomparable_pairs(): void
    {
        $matches = new ComplianceMatches();
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C4', 'VERY_HIGH'));
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C5', 'MEDIUM'));

        $result = $matches->strongestOnly();

        self::assertSame([
            ['standard' => 'ISO_12944', 'category' => 'C4', 'durability' => 'VERY_HIGH'],
            ['standard' => 'ISO_12944', 'category' => 'C5', 'durability' => 'MEDIUM'],
        ], $result->jsonSerialize());
    }

    public function test_strongest_only_treats_immersion_categories_as_separate_family(): void
    {
        $matches = new ComplianceMatches();
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C5', 'VERY_HIGH'));
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'Im1', 'HIGH'));
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'Im3', 'VERY_HIGH'));

        $result = $matches->strongestOnly();

        self::assertSame([
            ['standard' => 'ISO_12944', 'category' => 'C5', 'durability' => 'VERY_HIGH'],
            ['standard' => 'ISO_12944', 'category' => 'Im3', 'durability' => 'VERY_HIGH'],
        ], $result->jsonSerialize());
    }

    public function test_strongest_only_dedupes_exact_duplicates(): void
    {
        $matches = new ComplianceMatches();
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C3', 'HIGH'));
        $matches->add(new ComplianceMatch(ComplianceStandard::ISO_12944, 'C3', 'HIGH'));

        $result = $matches->strongestOnly();

        self::assertSame([
            ['standard' => 'ISO_12944', 'category' => 'C3', 'durability' => 'HIGH'],
        ], $result->jsonSerialize());
    }
}
