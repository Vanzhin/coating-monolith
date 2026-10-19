<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Type;

use App\Compliance\Domain\Type\FieldKind;
use App\Compliance\Domain\Type\JournalKind;
use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use App\Shared\Infrastructure\Exception\AppException;
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

    /**
     * Инвариант полноты обязательных полей — на самом enum, не через полный путь Requirement/ProfileCompliance:
     * JournalKind структурно недостижим ни у одного оставшегося типа требования (оба requiresQuantity()==true,
     * см. Requirement::setJournalKind), поэтому только так и остаётся покрыть сам throw.
     */
    public function test_assert_details_complete_throws_on_missing_required_field(): void
    {
        $this->expectException(AppException::class);
        // instructor_fio/instructor_doc обязательны у WorkplaceOt — передан только instruction_kind.
        JournalKind::WorkplaceOt->assertDetailsComplete(InstructionDetails::fromArray(['instruction_kind' => 'Повторный']));
    }

    public function test_assert_details_complete_passes_when_all_required_fields_present(): void
    {
        JournalKind::WorkplaceOt->assertDetailsComplete(InstructionDetails::fromArray([
            'instruction_kind' => 'Повторный',
            'instructor_fio' => 'Шумилов Александр Николаевич',
            'instructor_doc' => 'Удостоверение № 1',
        ]));

        $this->addToAssertionCount(1); // не бросило — это и есть ассерт
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
