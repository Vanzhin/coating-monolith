<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject\Instruction;

use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use PHPUnit\Framework\TestCase;

final class InstructionDetailsTest extends TestCase
{
    public function test_get_has_and_round_trip(): void
    {
        $details = InstructionDetails::fromArray([
            'instruction_kind' => 'Повторный',
            'local_acts' => ['Инструкция № 1', 'Инструкция № 2'],
            'reason' => '',
        ]);

        self::assertSame('Повторный', $details->get('instruction_kind'));
        self::assertSame(['Инструкция № 1', 'Инструкция № 2'], $details->get('local_acts'));
        self::assertNull($details->get('missing'));

        self::assertTrue($details->has('instruction_kind'));
        self::assertTrue($details->has('local_acts'));
        self::assertFalse($details->has('reason'), 'пустая строка — не заполнено');
        self::assertFalse($details->has('missing'));

        // Round-trip через jsonSerialize → fromArray идемпотентен.
        $again = InstructionDetails::fromArray($details->jsonSerialize());
        self::assertEquals($details, $again);
    }

    public function test_empty_is_allowed(): void
    {
        $details = InstructionDetails::fromArray([]);

        self::assertSame([], $details->jsonSerialize());
        self::assertFalse($details->has('anything'));
    }
}
