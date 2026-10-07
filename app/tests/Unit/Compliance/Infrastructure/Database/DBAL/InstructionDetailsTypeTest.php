<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use App\Compliance\Infrastructure\Database\DBAL\InstructionDetailsType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class InstructionDetailsTypeTest extends TestCase
{
    public function test_round_trip_through_jsonb(): void
    {
        $type = new InstructionDetailsType();
        $platform = new PostgreSQLPlatform();
        $vo = InstructionDetails::fromArray([
            'instruction_kind' => 'Повторный',
            'local_acts' => ['Инструкция № 1', 'Инструкция № 2'],
        ]);

        $db = $type->convertToDatabaseValue($vo, $platform);
        self::assertIsString($db);

        $back = $type->convertToPHPValue($db, $platform);
        self::assertEquals($vo, $back);
    }

    public function test_null_is_passed_through(): void
    {
        $type = new InstructionDetailsType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }
}
