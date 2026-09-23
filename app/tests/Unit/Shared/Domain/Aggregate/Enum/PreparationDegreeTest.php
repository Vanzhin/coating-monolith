<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\Enum;

use App\Shared\Domain\Aggregate\Enum\PreparationDegree;
use PHPUnit\Framework\TestCase;

final class PreparationDegreeTest extends TestCase
{
    public function test_value_round_trips_stored_code(): void
    {
        self::assertSame(PreparationDegree::Sa2Half, PreparationDegree::tryFrom('Sa 2½'));
    }

    public function test_document_text_is_full_sentence(): void
    {
        self::assertSame('Абразивоструйная очистка до степени Sa 2½ по ISO 8501-1.', PreparationDegree::Sa2Half->documentText());
        self::assertSame('Ручная и механизированная очистка до степени St 2 по ISO 8501-1.', PreparationDegree::St2->documentText());
    }

    public function test_every_case_has_non_empty_document_text(): void
    {
        foreach (PreparationDegree::cases() as $case) {
            self::assertNotSame('', $case->documentText(), $case->value);
        }
    }
}
