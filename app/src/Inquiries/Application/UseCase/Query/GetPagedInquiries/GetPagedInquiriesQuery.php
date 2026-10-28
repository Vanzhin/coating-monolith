<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetPagedInquiries;

use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedInquiriesQuery extends Query
{
    public function __construct(public InquiriesFilter $filter)
    {
    }
}
