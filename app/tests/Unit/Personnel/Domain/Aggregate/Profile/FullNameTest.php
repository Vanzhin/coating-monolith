<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class FullNameTest extends TestCase
{
    public function test_full_string_with_middle_name(): void
    {
        $fullName = new FullName('Иванов', 'Иван', 'Иванович');
        $this->assertSame('Иванов Иван Иванович', $fullName->fullString());
    }

    public function test_short_with_middle_name(): void
    {
        $fullName = new FullName('Иванов', 'Иван', 'Иванович');
        $this->assertSame('Иванов И. И.', $fullName->short());
    }

    public function test_full_string_without_middle_name(): void
    {
        $fullName = new FullName('Иванов', 'Иван');
        $this->assertSame('Иванов Иван', $fullName->fullString());
    }

    public function test_short_without_middle_name(): void
    {
        $fullName = new FullName('Иванов', 'Иван');
        $this->assertSame('Иванов И.', $fullName->short());
    }

    public function test_empty_last_name_throws(): void
    {
        $this->expectException(AppException::class);
        new FullName('   ', 'Иван');
    }

    public function test_empty_first_name_throws(): void
    {
        $this->expectException(AppException::class);
        new FullName('Иванов', '');
    }

    public function test_values_are_trimmed(): void
    {
        $fullName = new FullName('  Иванов  ', '  Иван  ', '  Иванович  ');
        $this->assertSame('Иванов', $fullName->lastName);
        $this->assertSame('Иван', $fullName->firstName);
        $this->assertSame('Иванович', $fullName->middleName);
    }

    public function test_blank_middle_name_normalizes_to_null(): void
    {
        $fullName = new FullName('Иванов', 'Иван', '   ');
        $this->assertNull($fullName->middleName);
    }

    public function test_round_trip_via_from_array(): void
    {
        $fullName = new FullName('Иванов', 'Иван', 'Иванович');

        $restored = FullName::fromArray($fullName->jsonSerialize());

        $this->assertSame($fullName->lastName, $restored->lastName);
        $this->assertSame($fullName->firstName, $restored->firstName);
        $this->assertSame($fullName->middleName, $restored->middleName);
    }

    public function test_round_trip_without_middle_name(): void
    {
        $fullName = new FullName('Иванов', 'Иван');

        $restored = FullName::fromArray($fullName->jsonSerialize());

        $this->assertSame($fullName->lastName, $restored->lastName);
        $this->assertSame($fullName->firstName, $restored->firstName);
        $this->assertNull($restored->middleName);
    }

    public function test_full_string_and_short_keep_literal_zero_middle_name(): void
    {
        $fullName = new FullName('Иванов', 'Иван', '0');

        $this->assertSame('Иванов Иван 0', $fullName->fullString());
        $this->assertSame('Иванов И. 0.', $fullName->short());
    }

    public function test_json_serialize_shape(): void
    {
        $fullName = new FullName('Иванов', 'Иван', 'Иванович');

        $this->assertSame(
            ['lastName' => 'Иванов', 'firstName' => 'Иван', 'middleName' => 'Иванович'],
            $fullName->jsonSerialize(),
        );
    }
}
