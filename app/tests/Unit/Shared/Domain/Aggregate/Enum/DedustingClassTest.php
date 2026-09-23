<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Aggregate\Enum;

use App\Shared\Domain\Aggregate\Enum\DedustingClass;
use PHPUnit\Framework\TestCase;

final class DedustingClassTest extends TestCase
{
    public function test_value_round_trips(): void
    {
        self::assertSame(DedustingClass::Class2, DedustingClass::tryFrom('2'));
    }

    public function test_document_text_is_full_sentence(): void
    {
        self::assertSame('класс 2 по количеству и размеру частиц пыли согласно ISO 8502-3.', DedustingClass::Class2->documentText());
    }

    public function test_every_case_has_non_empty_document_text(): void
    {
        foreach (DedustingClass::cases() as $case) {
            self::assertNotSame('', $case->documentText(), $case->value);
        }
    }
}
