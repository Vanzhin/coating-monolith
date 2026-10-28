<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\CreateInquiry;

final readonly class CreateInquiryCommandResult
{
    public function __construct(public string $id)
    {
    }
}
