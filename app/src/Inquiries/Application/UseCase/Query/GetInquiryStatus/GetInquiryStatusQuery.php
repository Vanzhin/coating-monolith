<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryStatus;

use App\Shared\Application\Query\Query;

final readonly class GetInquiryStatusQuery extends Query
{
    public function __construct(public string $inquiryId)
    {
    }
}
