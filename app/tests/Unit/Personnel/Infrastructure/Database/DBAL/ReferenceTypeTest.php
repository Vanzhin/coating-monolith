<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Infrastructure\Database\DBAL;

use App\Personnel\Domain\ValueObject\Reference;
use App\Personnel\Infrastructure\Database\DBAL\ReferenceType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

class ReferenceTypeTest extends TestCase
{
    private ReferenceType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new ReferenceType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function test_round_trip_preserves_reference(): void
    {
        $reference = new Reference('11111111-1111-1111-1111-111111111111', 'Маляр');

        $db = $this->type->convertToDatabaseValue($reference, $this->platform);
        self::assertIsString($db);

        $restored = $this->type->convertToPHPValue($db, $this->platform);
        self::assertInstanceOf(Reference::class, $restored);
        self::assertSame($reference->id, $restored->id);
        self::assertSame($reference->title, $restored->title);
    }

    public function test_null_roundtrip(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }
}
