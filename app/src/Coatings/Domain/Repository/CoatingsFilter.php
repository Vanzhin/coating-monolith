<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Infrastructure\Exception\AppException;

readonly class CoatingsFilter
{
    /**
     * Разрешённый диапазон длины поискового запроса.
     * Короче — бессмысленно (стеммер съест в стоп-слова, триграммы дадут мусор).
     * Длиннее — защита от случайного абзаца / DoS на FTS.
     */
    private const MIN_SEARCH_LENGTH = 3;
    private const MAX_SEARCH_LENGTH = 50;

    public ?string $search;

    public ?Pager $pager;

    public function __construct(
        ?string $search = null,
        public StringCollection $manufacturerIds = new StringCollection(),
        ?Pager $pager = null,
        public ?RangeFilter $applicationMinTemp = null,
        public ?RangeFilter $volumeSolid = null,
    ) {
        $this->search = $this->normalizeSearch($search);
        $this->pager = $pager;
    }

    private function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        $trimmed = trim($search);
        if ($trimmed === '') {
            return null;
        }
        $length = mb_strlen($trimmed);
        if ($length < self::MIN_SEARCH_LENGTH || $length > self::MAX_SEARCH_LENGTH) {
            throw new AppException(sprintf(
                'Длина поискового запроса должна быть от %d до %d символов.',
                self::MIN_SEARCH_LENGTH,
                self::MAX_SEARCH_LENGTH,
            ));
        }

        return $trimmed;
    }
}
