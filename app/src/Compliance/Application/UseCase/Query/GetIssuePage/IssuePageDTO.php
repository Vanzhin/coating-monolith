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
    /**
     * Требование несёт количество (material/periodic) — честный {@see \App\Compliance\Domain\Type\ComplianceType::requiresQuantity()}
     * от {@see self::$requirementType}, вычислен {@see GetIssuePageQueryHandler} ОДИН раз. Нужен personal-строкам
     * (добавляемым вне нормы): у них нет готовой проекции позиции ({@see IssueRowDTO::$showAmountInput}), поэтому
     * твиг гейтит ввод «Кол-во»/«Ед.» этим флагом, а не литералом типа.
     */
    public bool $requirementRequiresQuantity = true;
    /**
     * Держит ли требование-контейнер смешанные позиции (инвентарь+расходник) — {@see
     * \App\Compliance\Domain\Type\ComplianceType::allowsMixedItems()} от {@see self::$requirementType}. Форма
     * показывает по нему выбор «Вид» у позиции, добавляемой вне нормы (на «Периодической» все позиции и так
     * периодические — выбор не нужен).
     */
    public bool $requirementAllowsMixed = false;
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
