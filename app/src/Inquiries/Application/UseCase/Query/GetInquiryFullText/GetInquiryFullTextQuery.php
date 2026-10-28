<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryFullText;

use App\Shared\Application\Query\Query;

final readonly class GetInquiryFullTextQuery extends Query
{
    public function __construct(public string $inquiryId)
    {
    }
}
