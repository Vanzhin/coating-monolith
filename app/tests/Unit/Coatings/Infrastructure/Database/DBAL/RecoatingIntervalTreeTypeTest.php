<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Infrastructure\Database\DBAL\RecoatingIntervalTreeType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

final class RecoatingIntervalTreeTypeTest extends TestCase
{
    private Type $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        if (!Type::hasType(RecoatingIntervalTreeType::NAME)) {
            Type::addType(RecoatingIntervalTreeType::NAME, RecoatingIntervalTreeType::class);
        }

        $this->type = Type::getType(RecoatingIntervalTreeType::NAME);
        $this->platform = new PostgreSQLPlatform();
    }

    public function test_round_trip_leaf(): void
    {
        $tree = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14)),
        );

        $db = $this->type->convertToDatabaseValue($tree, $this->platform);
        $restored = $this->type->convertToPHPValue($db, $this->platform);

        $this->assertInstanceOf(RecoatingIntervalTree::class, $restored);
        $this->assertSame(
            json_decode(json_encode($tree), true),
            json_decode(json_encode($restored), true),
        );
    }

    public function test_round_trip_nested(): void
    {
        $tree = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 7)),
                'atmospheric',
                new RecoatingIntervalTree(
                    new DryingTimeSeries(new TimeAtTemperature(20, 30)),
                    'EP',
                ),
            ),
        );

        $db = $this->type->convertToDatabaseValue($tree, $this->platform);
        $restored = $this->type->convertToPHPValue($db, $this->platform);

        $this->assertSame(
            json_decode(json_encode($tree), true),
            json_decode(json_encode($restored), true),
        );
    }

    public function test_null_value_round_trips(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function test_rejects_wrong_php_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->type->convertToDatabaseValue('not a tree', $this->platform);
    }
}
