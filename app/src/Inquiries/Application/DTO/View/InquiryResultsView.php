<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;

/**
 * Страница найденных систем для ленты результатов запроса (постранично, как список систем).
 * total — сколько систем в снимке ответа (по нему считаются страницы «Показать ещё»).
 */
final readonly class InquiryResultsView
{
    /** Размер страницы ленты результатов — как у списка систем. */
    public const PER_PAGE = 20;

    /** @param list<CoatingSystemDTO> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
    ) {
    }

    public function pages(): int
    {
        return (int) ceil($this->total / self::PER_PAGE);
    }
}
