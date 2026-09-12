<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\MixingRatio;
use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class MixingRatioTest extends TestCase
{
    public function test_accepts_volume_only(): void
    {
        $ratio = new MixingRatio(byVolume: new PartsRatio(3.0, 1.0));

        self::assertSame([3.0, 1.0], $ratio->getByVolume()?->getParts());
        self::assertNull($ratio->getByMass());
    }

    public function test_accepts_mass_only(): void
    {
        $ratio = new MixingRatio(byMass: new PartsRatio(100.0, 23.0));

        self::assertNull($ratio->getByVolume());
        self::assertSame([100.0, 23.0], $ratio->getByMass()?->getParts());
    }

    public function test_accepts_both_bases(): void
    {
        $ratio = new MixingRatio(new PartsRatio(3.0, 1.0), new PartsRatio(100.0, 23.0));

        self::assertSame([3.0, 1.0], $ratio->getByVolume()?->getParts());
        self::assertSame([100.0, 23.0], $ratio->getByMass()?->getParts());
    }

    public function test_rejects_when_no_base_given(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/хотя бы одну/');
        new MixingRatio();
    }

    public function test_rejects_component_count_mismatch_between_bases(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/число компонентов/');
        new MixingRatio(new PartsRatio(3.0, 1.0), new PartsRatio(4.0, 1.0, 0.5));
    }

    public function test_json_roundtrip_both_bases(): void
    {
        $original = new MixingRatio(new PartsRatio(3.0, 1.0), new PartsRatio(100.0, 23.0));
        $restored = MixingRatio::fromArray($original->jsonSerialize());

        self::assertEquals($original, $restored);
    }

    public function test_json_roundtrip_volume_only(): void
    {
        $original = new MixingRatio(byVolume: new PartsRatio(4.0, 1.0, 0.5));
        $restored = MixingRatio::fromArray($original->jsonSerialize());

        self::assertEquals($original, $restored);
    }
}
