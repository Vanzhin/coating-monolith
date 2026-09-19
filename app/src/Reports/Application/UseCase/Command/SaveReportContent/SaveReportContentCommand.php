<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\SaveReportContent;

use App\Shared\Application\Command\Command;

final readonly class SaveReportContentCommand extends Command
{
    /**
     * @param array<string, mixed> $content содержимое по блокам {blockKey: {fieldKey: value}}
     */
    public function __construct(
        public string $reportId,
        public array $content,
    ) {
    }
}
