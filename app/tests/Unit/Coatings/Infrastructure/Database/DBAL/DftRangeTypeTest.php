<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Infrastructure\Database\DBAL\DftRangeType;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

class DftRangeTypeTest extends TestCase
{
    private Type $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        if (!Type::hasType(DftRangeType::NAME)) {
            Type::addType(DftRangeType::NAME, DftRangeType::class);
        }

        $this->type = Type::getType(DftRangeType::NAME);
        $this->platform = new PostgreSQLPlatform();
    }

    public function test_dft_range_is_serialized_to_json(): void
    {
        $dftRange = new DftRange(
            new PositiveNumberRange(50, 150),
            100,
            ThicknessType::MIC,
        );

        $json = $this->type->convertToDatabaseValue($dftRange, $this->platform);

        $this->assertSame(
            '{"min":50,"max":150,"tds_dft":100,"type":"\u043c\u043a\u043c"}',
            $json,
        );
    }

    public function test_json_is_deserialized_back_to_dft_range(): void
    {
        $json = '{"min":50,"max":150,"tds_dft":100,"type":"\u043c\u043a\u043c"}';

        $dftRange = $this->type->convertToPHPValue($json, $this->platform);

        $this->assertInstanceOf(DftRange::class, $dftRange);
        $this->assertSame(50, $dftRange->range->getMin());
        $this->assertSame(150, $dftRange->range->getMax());
        $this->assertSame(100, $dftRange->tdsDft);
        $this->assertSame(ThicknessType::MIC, $dftRange->type);
    }

    public function test_null_roundtrip(): void
    {
        $this->assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
    }
}
