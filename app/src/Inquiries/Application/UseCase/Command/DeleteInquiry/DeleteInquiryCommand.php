<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\DeleteInquiry;

use App\Shared\Application\Command\Command;

/**
 * Удалить запрос целиком — любой статус (мусор, спам, битый файл, в т.ч. одобренный). Admin-only.
 */
final readonly class DeleteInquiryCommand extends Command
{
    public function __construct(public string $inquiryId)
    {
    }
}
