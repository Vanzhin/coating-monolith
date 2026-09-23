<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\Enum;

use App\Shared\Domain\Aggregate\Enum\RustGrade;
use PHPUnit\Framework\TestCase;

final class RustGradeTest extends TestCase
{
    public function test_value_round_trips(): void
    {
        self::assertSame(RustGrade::B, RustGrade::tryFrom('B'));
    }

    public function test_document_text_is_short_gost(): void
    {
        self::assertSame('Степень A по ГОСТ Р ИСО 8501-1-2014', RustGrade::A->documentText());
        self::assertSame('Степень D по ГОСТ Р ИСО 8501-1-2014', RustGrade::D->documentText());
    }

    public function test_every_case_has_non_empty_document_text(): void
    {
        foreach (RustGrade::cases() as $case) {
            self::assertNotSame('', $case->documentText(), $case->value);
        }
    }
}
