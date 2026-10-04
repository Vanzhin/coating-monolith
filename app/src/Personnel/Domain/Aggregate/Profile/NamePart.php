<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Часть ФИО (фамилия/имя/отчество): непустая строка только из букв (любой алфавит), дефиса, пробела и
 * апострофа — двойные фамилии (Иванов-Петров), составные имена (Анна-Мария), иностранные (О'Брайен, де Ла Круз).
 * Цифры, знаки препинания и прочие символы запрещены. Владелец правила «как выглядит часть имени».
 */
final readonly class NamePart implements \Stringable
{
    private const PATTERN = '/^[\p{L} \'\-]+$/u';

    public string $value;

    public function __construct(string $value, string $field)
    {
        $value = trim($value);
        if ('' === $value) {
            throw new AppException(sprintf('%s не может быть пустым.', $field));
        }
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new AppException(sprintf('%s: допустимы только буквы, дефис, пробел и апостроф.', $field));
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
