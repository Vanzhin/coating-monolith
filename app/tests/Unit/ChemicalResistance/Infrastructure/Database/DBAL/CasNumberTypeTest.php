<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChemicalResistance\Infrastructure\Database\DBAL;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Infrastructure\Database\DBAL\CasNumberType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class CasNumberTypeTest extends TestCase
{
    private CasNumberType $type;

    protected function setUp(): void
    {
        if (!\Doctrine\DBAL\Types\Type::hasType('cas_number')) {
            \Doctrine\DBAL\Types\Type::addType('cas_number', CasNumberType::class);
        }
        $this->type = \Doctrine\DBAL\Types\Type::getType('cas_number');
    }

    public function test_to_php_and_back(): void
    {
        $plat = new PostgreSQLPlatform();
        self::assertNull($this->type->convertToPHPValue(null, $plat));
        self::assertNull($this->type->convertToDatabaseValue(null, $plat));

        $cas = $this->type->convertToPHPValue('107-21-1', $plat);
        self::assertInstanceOf(CasNumber::class, $cas);
        self::assertSame('107-21-1', $cas->value);

        self::assertSame('107-21-1', $this->type->convertToDatabaseValue(CasNumber::fromString('107-21-1'), $plat));
    }
}
