<?php
declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Infrastructure\Database\DBAL\DryingTimeSeriesType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

class DryingTimeSeriesTypeTest extends TestCase
{
    private Type $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        if (!Type::hasType(DryingTimeSeriesType::NAME)) {
            Type::addType(DryingTimeSeriesType::NAME, DryingTimeSeriesType::class);
        }

        $this->type = Type::getType(DryingTimeSeriesType::NAME);
        $this->platform = new PostgreSQLPlatform();
    }

    public function testSeriesIsSerializedToJsonWithSnakeCaseFields(): void
    {
        $series = new DryingTimeSeries(
            new TimeAtTemperature(20, 10.0),
            new TimeAtTemperature(30, 5.0),
        );

        $json = $this->type->convertToDatabaseValue($series, $this->platform);

        $this->assertSame(
            '[{"temperature_at":20,"time_in_minutes":10.0,"is_calculated":false},'
            . '{"temperature_at":30,"time_in_minutes":5.0,"is_calculated":false}]',
            $json,
        );
    }

    public function testJsonIsDeserializedBackToSeries(): void
    {
        $json = '[{"temperature_at":20,"time_in_minutes":10.0,"is_calculated":false},'
            . '{"temperature_at":30,"time_in_minutes":5.0,"is_calculated":false}]';

        $series = $this->type->convertToPHPValue($json, $this->platform);

        $this->assertInstanceOf(DryingTimeSeries::class, $series);
        $this->assertCount(2, $series->points);
        $this->assertSame([20, 30], array_keys($series->points));
        $this->assertSame(10.0, $series->points[20]->getValue());
    }

    public function testNullRoundtrip(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }
}
