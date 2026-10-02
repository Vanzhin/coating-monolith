<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignDraft;

use App\Shared\Application\Command\Command;

/**
 * Оформить черновик: приложить подписанный скан и заморозить акт. Строки выдачи — из формы (количество/дата/
 * износ/крайняя дата построчно). Инвариант (скан + не меньше нормы) проверяет домен.
 *
 * @phpstan-type IssuanceInput array{obligationKey?: string, date?: string, amount?: string, unit?: string, wearPercent?: string, manualDueDate?: string}
 */
readonly class SignDraftCommand extends Command
{
    /** @param list<IssuanceInput> $items */
    public function __construct(
        public string $profileId,
        public string $documentId,
        public string $documentDate,
        public array $items,
        public ?string $stagedFileId = null,
    ) {
    }
}
