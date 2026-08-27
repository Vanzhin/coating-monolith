<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Compliance\Sp28;

use App\Coatings\Domain\Compliance\Sp28\Sp28Facets;
use App\Coatings\Domain\Compliance\Sp28\SpAggressivity;
use PHPUnit\Framework\TestCase;

final class Sp28FacetsTest extends TestCase
{
    public function test_every_aggressivity_degree_has_conditions_axis(): void
    {
        $facets = new Sp28Facets();

        // У СП условия эксплуатации есть для любой степени агрессивности.
        foreach (SpAggressivity::cases() as $degree) {
            self::assertTrue($facets->hasSecondaryAxis($degree->value));
        }
    }
}
