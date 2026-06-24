<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use PHPUnit\Framework\TestCase;

final class EnvironmentTypeTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $values = array_map(static fn(EnvironmentType $c) => $c->value, EnvironmentType::cases());
        sort($values);
        $this->assertSame(['atmospheric', 'immersion', 'special'], $values);
    }

    public function testFromValue(): void
    {
        $this->assertSame(EnvironmentType::Atmospheric, EnvironmentType::from('atmospheric'));
        $this->assertSame(EnvironmentType::Immersion,   EnvironmentType::from('immersion'));
        $this->assertSame(EnvironmentType::Special,     EnvironmentType::from('special'));
    }
}
