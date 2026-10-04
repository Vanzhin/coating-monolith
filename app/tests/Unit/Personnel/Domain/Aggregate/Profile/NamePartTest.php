<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\NamePart;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamePartTest extends TestCase
{
    public function test_plain_letters(): void
    {
        self::assertSame('Иванов', (new NamePart('Иванов', 'Фамилия'))->value);
    }

    public function test_trims(): void
    {
        self::assertSame('Иван', (new NamePart('  Иван  ', 'Имя'))->value);
    }

    public function test_stringable(): void
    {
        self::assertSame('Иван', (string) new NamePart('Иван', 'Имя'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedProvider(): iterable
    {
        yield 'дефис (двойная фамилия)' => ['Иванов-Петров'];
        yield 'составное имя' => ['Анна-Мария'];
        yield 'апостроф' => ["О'Брайен"];
        yield 'пробел (составная)' => ['де Ла Круз'];
        yield 'латиница' => ['Smith'];
    }

    #[DataProvider('allowedProvider')]
    public function test_allowed_values(string $value): void
    {
        self::assertSame($value, (new NamePart($value, 'Фамилия'))->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'пусто' => [''];
        yield 'пробелы' => ['   '];
        yield 'цифра' => ['Иван0в'];
        yield 'только цифра' => ['0'];
        yield 'символ @' => ['Иван@'];
        yield 'точка (инициал)' => ['И.'];
        yield 'подчёркивание' => ['Иван_ов'];
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejected_values(string $value): void
    {
        $this->expectException(AppException::class);
        new NamePart($value, 'Фамилия');
    }
}
