<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\FullTextSearch;

/**
 * Строит PostgreSQL tsquery с префиксным сопоставлением из пользовательского ввода.
 * Санитайзит tsquery-мета, разбивает на слова, объединяет через AND (`&`) или OR (`|`).
 * Пример (AND): «быстросох эпоксидн» → «быстросох:* & эпоксидн:*».
 */
final class PrefixTsQueryBuilder
{
    /** Слова объединяются через `&` — совпадать должны все. */
    public const CONJUNCTION_AND = '&';
    /** Слова объединяются через `|` — совпадать может любое. */
    public const CONJUNCTION_OR = '|';

    public function build(string $input, string $conjunction = self::CONJUNCTION_AND): string
    {
        if (!in_array($conjunction, [self::CONJUNCTION_AND, self::CONJUNCTION_OR], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown tsquery conjunction: %s', $conjunction));
        }

        $sanitized = preg_replace('/[&|!()<>:\'"\\\\*]/u', ' ', $input) ?? '';
        $words = preg_split('/[\s\-.,;]+/u', trim($sanitized), -1, PREG_SPLIT_NO_EMPTY);

        if (false === $words || [] === $words) {
            return '';
        }

        return implode(' '.$conjunction.' ', array_map(static fn (string $w) => $w.':*', $words));
    }
}
