<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\ApproveInquiry;

use App\Shared\Application\Command\Command;

/**
 * Инженер одобряет результат подбора. Параметры к этому моменту — финальная версия инженера
 * (правка = переподбор отдельной операцией), поэтому команде нужен только id запроса.
 */
final readonly class ApproveInquiryCommand extends Command
{
    public function __construct(public string $inquiryId)
    {
    }
}
