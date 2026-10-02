<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignWriteOffAct;

use App\Shared\Application\Command\Command;

/** Оформить акт списания: комиссия (профили организации) + №/дата + скан → акт заморожен, эффект списания. Причины уже заданы на странице акта. */
readonly class SignWriteOffActCommand extends Command
{
    /** @param list<string> $memberProfileIds */
    public function __construct(
        public string $profileId,
        public string $writeOffActId,
        public string $representativeProfileId,
        public array $memberProfileIds,
        public string $actNumber,
        public string $actDate,
        public ?string $stagedFileId = null,
    ) {
    }
}
