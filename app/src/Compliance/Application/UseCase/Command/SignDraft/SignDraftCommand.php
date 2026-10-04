<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignDraft;

use App\Shared\Application\Command\Command;

/**
 * Оформить черновик: приложить подписанный скан и заморозить акт. Строки выдачи — из формы (количество/дата/
 * износ/крайняя дата построчно). Инвариант (скан + не меньше нормы) проверяет домен. `personalItems` —
 * добавленные прямо в акт позиции вне нормы (origin=Personal): наименование + срок окончания (+ кол-во для
 * материального акта); материализуются в обязанности при оформлении.
 *
 * @phpstan-type IssuanceInput array{obligationKey?: string, date?: string, amount?: string, unit?: string, wearPercent?: string, manualDueDate?: string}
 * @phpstan-type PersonalInput array{label?: string, amount?: string, unit?: string, manualDueDate?: string}
 */
readonly class SignDraftCommand extends Command
{
    /**
     * @param list<IssuanceInput> $items
     * @param list<PersonalInput> $personalItems
     */
    public function __construct(
        public string $profileId,
        public string $documentId,
        public string $documentDate,
        public array $items,
        public string $actNumber,
        public string $responsibleFio,
        public ?string $stagedFileId = null,
        public array $personalItems = [],
    ) {
    }
}
