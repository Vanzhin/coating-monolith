<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetPagedInquiries;

use App\Shared\Application\Query\Query;

final readonly class GetPagedInquiriesQuery extends Query
{
    public function __construct(
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
