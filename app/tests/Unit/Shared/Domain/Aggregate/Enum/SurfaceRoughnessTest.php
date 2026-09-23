<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\Enum;

use App\Shared\Domain\Aggregate\Enum\SurfaceRoughness;
use PHPUnit\Framework\TestCase;

final class SurfaceRoughnessTest extends TestCase
{
    public function test_value_round_trips(): void
    {
        self::assertSame(SurfaceRoughness::MediumG, SurfaceRoughness::tryFrom('Средний G'));
    }

    public function test_document_text_is_full_sentence(): void
    {
        self::assertSame('Средний G – между 2 и 3 сегментами, исключая сегмент 3, компаратора G по ISO 8503-2.', SurfaceRoughness::MediumG->documentText());
        self::assertSame('Грубее грубого S – крупнее сегмента 4 компаратора S по ISO 8503-2.', SurfaceRoughness::CoarserCoarseS->documentText());
    }

    public function test_ten_cases_grade_by_comparator_all_non_empty(): void
    {
        self::assertCount(10, SurfaceRoughness::cases());
        foreach (SurfaceRoughness::cases() as $case) {
            self::assertNotSame('', $case->documentText(), $case->value);
        }
    }
}
