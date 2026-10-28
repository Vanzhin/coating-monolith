<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryEditForm;

use App\Shared\Application\Query\Query;

final readonly class GetInquiryEditFormQuery extends Query
{
    public function __construct(public string $inquiryId)
    {
    }
}
