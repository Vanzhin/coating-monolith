<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryResults;

use App\Shared\Application\Query\Query;

/**
 * Страница найденных систем для запроса — ЖИВОЙ прогон распознанных параметров по каталогу (не снимок).
 * Постранично, как список систем: page → очередная пачка полных карточек.
 */
final readonly class GetInquiryResultsQuery extends Query
{
    public function __construct(
        public string $inquiryId,
        public int $page = 1,
    ) {
    }
}
