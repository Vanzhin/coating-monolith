<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignWriteOffAct;

use App\Shared\Application\Command\Command;

/** Оформить акт списания: комиссия (свободные строки организация/должность/ФИО/дата) + №/дата + скан → акт заморожен, эффект списания. Причины уже заданы на странице акта. */
readonly class SignWriteOffActCommand extends Command
{
    /** @param list<array<string, mixed>> $members Строки комиссии: organization/position/name(fio)/date. */
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
        public array $members,
        public string $actNumber,
        public string $actDate,
        public ?string $stagedFileId = null,
    ) {
    }
}
