<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveDraft;

use App\Shared\Application\Command\Command;

/**
 * Сохранить черновик-корзину. Без скана (`stagedFileId` пуст) — просто кладём строки и реквизиты (№/ответственный)
 * на черновик, он остаётся незаполненным-в-held. Со сканом — ОФОРМЛЯЕМ: тот же ввод + приложенный скан → домен
 * проверяет инвариант (не меньше нормы) и подписывает акт. Строки выдачи — из формы (количество/дата/износ/крайняя
 * дата построчно). `personalItems` — добавленные прямо в акт позиции вне нормы (origin=Personal): наименование +
 * срок окончания (+ кол-во для материального акта); материализуются в обязанности.
 *
 * @phpstan-type IssuanceInput array{obligationKey?: string, date?: string, amount?: string, unit?: string, wearPercent?: string, manualDueDate?: string}
 * @phpstan-type PersonalInput array{label?: string, amount?: string, unit?: string, manualDueDate?: string}
 */
readonly class SaveDraftCommand extends Command
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
