<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\FieldKind;
use App\Compliance\Domain\Type\JournalKind;
use PHPUnit\Framework\TestCase;

final class JournalKindTest extends TestCase
{
    public function test_fire_safety_schema(): void
    {
        $byKey = $this->byKey(JournalKind::FireSafety);

        self::assertArrayHasKey('instruction_kind', $byKey);
        self::assertSame(FieldKind::Select, $byKey['instruction_kind']->kind);
        self::assertTrue($byKey['instruction_kind']->required);
        self::assertNotEmpty($byKey['instruction_kind']->options, 'у вида инструктажа есть варианты');
        self::assertArrayHasKey('practice_date', $byKey, 'у пожарного есть практическая часть');
        self::assertSame(FieldKind::Date, $byKey['practice_date']->kind);
    }

    public function test_workplace_ot_schema(): void
    {
        $byKey = $this->byKey(JournalKind::WorkplaceOt);

        self::assertArrayHasKey('instruction_kind', $byKey);
        self::assertArrayHasKey('reason', $byKey, 'у ОТ есть причина');
        self::assertArrayHasKey('instructor_doc', $byKey);
        // Локальные акты и дата рождения — НЕ инструктаж-поля: берутся из требования (основание) и профиля.
        self::assertArrayNotHasKey('local_acts', $byKey);
        self::assertArrayNotHasKey('birth_date', $byKey);
    }

    public function test_labels_present(): void
    {
        self::assertNotSame('', JournalKind::FireSafety->label());
        self::assertNotSame('', JournalKind::WorkplaceOt->label());
    }

    /** @return array<string, \App\Compliance\Domain\Type\FieldSpec> */
    private function byKey(JournalKind $kind): array
    {
        $byKey = [];
        foreach ($kind->fields() as $field) {
            $byKey[$field->key] = $field;
        }

        return $byKey;
    }
}
