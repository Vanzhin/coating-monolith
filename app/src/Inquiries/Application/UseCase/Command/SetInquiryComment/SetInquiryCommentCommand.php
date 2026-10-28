<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\SetInquiryComment;

use App\Shared\Application\Command\Command;

/**
 * Инженер задаёт/правит свободную заметку по запросу. Редактируется в любой момент.
 */
final readonly class SetInquiryCommentCommand extends Command
{
    public function __construct(
        public string $inquiryId,
        public string $comment,
    ) {
    }
}
