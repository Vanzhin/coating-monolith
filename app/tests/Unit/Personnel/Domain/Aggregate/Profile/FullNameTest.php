<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Personnel\Domain\Aggregate\Profile\NamePart;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class FullNameTest extends TestCase
{
    public function test_constructor_accepts_name_parts(): void
    {
        $fullName = new FullName(new NamePart('Иванов', 'Фамилия'), new NamePart('Иван', 'Имя'));
        $this->assertSame('Иванов', $fullName->lastName->value);
        $this->assertSame('Иван', $fullName->firstName->value);
        $this->assertNull($fullName->middleName);
    }

    public function test_full_string_with_middle_name(): void
    {
        $fullName = FullName::of('Иванов', 'Иван', 'Иванович');
        $this->assertSame('Иванов Иван Иванович', $fullName->fullString());
    }

    public function test_short_with_middle_name(): void
    {
        $fullName = FullName::of('Иванов', 'Иван', 'Иванович');
        $this->assertSame('Иванов И. И.', $fullName->short());
    }

    public function test_full_string_without_middle_name(): void
    {
        $fullName = FullName::of('Иванов', 'Иван');
        $this->assertSame('Иванов Иван', $fullName->fullString());
    }

    public function test_short_without_middle_name(): void
    {
        $fullName = FullName::of('Иванов', 'Иван');
        $this->assertSame('Иванов И.', $fullName->short());
    }

    public function test_empty_last_name_throws(): void
    {
        $this->expectException(AppException::class);
        FullName::of('   ', 'Иван');
    }

    public function test_empty_first_name_throws(): void
    {
        $this->expectException(AppException::class);
        FullName::of('Иванов', '');
    }

    public function test_values_are_trimmed(): void
    {
        $fullName = FullName::of('  Иванов  ', '  Иван  ', '  Иванович  ');
        $this->assertSame('Иванов', $fullName->lastName->value);
        $this->assertSame('Иван', $fullName->firstName->value);
        $this->assertSame('Иванович', $fullName->middleName?->value);
    }

    public function test_blank_middle_name_normalizes_to_null(): void
    {
        $fullName = FullName::of('Иванов', 'Иван', '   ');
        $this->assertNull($fullName->middleName);
    }

    public function test_round_trip_via_from_array(): void
    {
        $fullName = FullName::of('Иванов', 'Иван', 'Иванович');

        $restored = FullName::fromArray($fullName->jsonSerialize());

        $this->assertSame($fullName->lastName->value, $restored->lastName->value);
        $this->assertSame($fullName->firstName->value, $restored->firstName->value);
        $this->assertSame($fullName->middleName?->value, $restored->middleName?->value);
    }

    public function test_round_trip_without_middle_name(): void
    {
        $fullName = FullName::of('Иванов', 'Иван');

        $restored = FullName::fromArray($fullName->jsonSerialize());

        $this->assertSame($fullName->lastName->value, $restored->lastName->value);
        $this->assertSame($fullName->firstName->value, $restored->firstName->value);
        $this->assertNull($restored->middleName);
    }

    public function test_digit_in_name_throws(): void
    {
        $this->expectException(AppException::class);
        FullName::of('Иванов', 'Иван', '0');
    }

    public function test_symbol_in_last_name_throws(): void
    {
        $this->expectException(AppException::class);
        FullName::of('Иван@в', 'Иван');
    }

    public function test_double_surname_and_compound_name_allowed(): void
    {
        $fullName = FullName::of('Иванов-Петров', 'Анна-Мария', "О'Брайен");
        $this->assertSame('Иванов-Петров Анна-Мария О\'Брайен', $fullName->fullString());
    }

    public function test_json_serialize_shape(): void
    {
        $fullName = FullName::of('Иванов', 'Иван', 'Иванович');

        $this->assertSame(
            ['lastName' => 'Иванов', 'firstName' => 'Иван', 'middleName' => 'Иванович'],
            $fullName->jsonSerialize(),
        );
    }
}
