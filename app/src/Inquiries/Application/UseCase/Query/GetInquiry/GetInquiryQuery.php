<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiry;

use App\Shared\Application\Query\Query;

final readonly class GetInquiryQuery extends Query
{
    public function __construct(public string $inquiryId)
    {
    }
}
