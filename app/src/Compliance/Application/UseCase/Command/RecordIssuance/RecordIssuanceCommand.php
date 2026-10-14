<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecordIssuance;

use App\Shared\Application\Command\Command;

/**
 * Записать выдачу/прохождение ПРЯМО в журнал — без черновика, топ-апа и подписи-над-выдачей. Строки формы
 * (по позиции текущей нормы) становятся неизменяемыми фактами сразу. `personalItems` — добавленные вне нормы
 * позиции (origin=Personal): наименование + срок окончания (+ кол-во у материального требования);
 * материализуются в персональные обязанности. Для не материального требования с видом журнала каждая строка
 * несёт поля инструктажа — их полноту проверяет домен при записи ({@see \App\Compliance\Domain\Type\JournalKind}).
 *
 * @phpstan-type IssuanceInput array{obligationKey?: string, date?: string, amount?: string, unit?: string, wearPercent?: string, manualDueDate?: string, note?: string, instruction?: array<string, mixed>}
 * @phpstan-type PersonalInput array{label?: string, amount?: string, unit?: string, manualDueDate?: string, note?: string, instruction?: array<string, mixed>}
 */
readonly class RecordIssuanceCommand extends Command
{
    /**
     * @param list<IssuanceInput> $items
     * @param list<PersonalInput> $personalItems
     */
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public string $documentDate,
        public array $items = [],
        public array $personalItems = [],
    ) {
    }
}
