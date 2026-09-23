<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Block;

use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Domain\Aggregate\Enum\PreparationDegree;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    public function test_enum_backed_field_derives_options_from_cases(): void
    {
        $field = new Field('prepDegree', FieldType::Enum, 'Степень подготовки', enum: PreparationDegree::class);

        self::assertSame(['Sa 1', 'Sa 2', 'Sa 2½', 'Sa 3', 'St 2', 'St 3'], $field->options);
        self::assertSame(PreparationDegree::class, $field->enum);
    }

    public function test_explicit_options_win_over_enum(): void
    {
        $field = new Field('x', FieldType::Enum, 'X', options: ['a', 'b'], enum: PreparationDegree::class);

        self::assertSame(['a', 'b'], $field->options);
    }

    public function test_field_without_enum_has_no_options(): void
    {
        $field = new Field('x', FieldType::Text, 'X');

        self::assertSame([], $field->options);
        self::assertNull($field->enum);
    }
}
