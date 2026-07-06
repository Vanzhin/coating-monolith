<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Нормализованный поисковой запрос.
 *
 *  - Пустая строка / пробелы → null через фабрику tryFromString (нет запроса, не VO).
 *  - Длина после trim: 3–50 символов; иначе AppException. Короче — бессмысленно
 *    (стеммер съест в стоп-слова, триграммы дадут мусор), длиннее — защита от
 *    случайного абзаца / DoS на FTS.
 *  - words() — единственная точка разбиения на слова: одинаковый splitter для
 *    fuzzy-fallback («single-word only») и prefix-tsquery builder'а.
 */
final readonly class SearchQuery
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 50;

    public function __construct(public string $value)
    {
        $length = mb_strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new AppException(sprintf(
                'Длина поискового запроса должна быть от %d до %d символов.',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
            ));
        }
    }

    /** null-in → null-out; пустая строка после trim тоже null. */
    public static function tryFromString(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        return new self($trimmed);
    }

    /** @return list<string> */
    public function words(): array
    {
        $words = preg_split('/[\s\-.,;]+/u', $this->value, -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? [] : $words;
    }

    public function hasSingleWord(): bool
    {
        return count($this->words()) === 1;
    }
}
