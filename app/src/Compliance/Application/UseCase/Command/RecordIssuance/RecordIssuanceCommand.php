<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecordIssuance;

use App\Shared\Application\Command\Command;

/**
 * Зафиксировать выдачу/прохождение по требованию для человека. Дата документа — общая по умолчанию;
 * у каждой позиции своя дата может переопределяться. Кол-во/износ — у материальных; manualDueDate — для
 * «по документам изготовителя»; stagedFileId — скан (двухфазная загрузка).
 *
 * @phpstan-type ItemInput array{obligationKey?: string, date?: string, amount?: string, unit?: string, wearPercent?: string, manualDueDate?: string, stagedFileId?: string}
 */
readonly class RecordIssuanceCommand extends Command
{
    /**
     * @param list<ItemInput> $items
     */
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public string $documentDate,
        public array $items,
        public ?string $note = null,
    ) {
    }
}
