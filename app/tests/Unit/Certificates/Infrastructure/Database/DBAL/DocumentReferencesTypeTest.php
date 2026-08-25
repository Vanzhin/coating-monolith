<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Infrastructure\Database\DBAL;

use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Infrastructure\Database\DBAL\DocumentReferencesType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class DocumentReferencesTypeTest extends TestCase
{
    private DocumentReferencesType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new DocumentReferencesType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function test_round_trip_preserves_references(): void
    {
        $refs = [
            new Reference(ReferenceType::CoatingSystem, Uuid::v7()),
            new Reference(ReferenceType::Coating, Uuid::v7()),
        ];

        $db = $this->type->convertToDatabaseValue($refs, $this->platform);
        self::assertIsString($db);

        $restored = $this->type->convertToPHPValue($db, $this->platform);
        self::assertCount(2, $restored);
        self::assertTrue($restored[0]->equals($refs[0]));
        self::assertTrue($restored[1]->equals($refs[1]));
    }

    public function test_null_becomes_null_on_write_and_empty_on_read(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertSame([], $this->type->convertToPHPValue(null, $this->platform));
    }

    public function test_declares_jsonb_column(): void
    {
        self::assertSame('JSONB', $this->type->getSQLDeclaration([], $this->platform));
    }
}
