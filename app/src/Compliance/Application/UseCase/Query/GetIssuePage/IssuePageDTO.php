<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

use App\Personnel\Application\DTO\Profile\ProfileDTO;

/** Read-модель формы `/issue`, собранная {@see GetIssuePageQueryHandler}. Контроллер только рендерит. */
final class IssuePageDTO
{
    public ProfileDTO $profile;
    public string $requirementName = 'Требование';
    public string $requirementType = 'material';
    /** @var list<IssueRowDTO> позиции нормы, отсортированные по срочности (просрочено/не выдано → скоро → в норме) */
    public array $rows = [];
    /** @var list<IssueInstructionGroupDTO> */
    public array $instructionGroups = [];
    /** № карточки СИЗ — свойство журнала; null, если ещё не закреплён первой выдачей. */
    public ?string $cardNumber = null;
    public bool $canWriteOff = false;
    /** @var list<IssueWriteOffActSummaryDTO> */
    public array $writeOffActs = [];
}
