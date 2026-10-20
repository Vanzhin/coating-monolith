<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

/**
 * Строка позиции в форме выдачи: EAGER-часть единой карточки (бейдж/норма/на руках/сроки) — собрана
 * {@see GetIssuePageQueryHandler} из read-модели. `savedInstruction`/`selected` — не read-модель, а presentation
 * merge текущего запроса (восстановление формы после ошибки сабмита); {@see \App\Compliance\Infrastructure\Controller\Fulfillment\IssueAction}
 * заполняет их ПОСЛЕ получения страницы, до рендера.
 */
final class IssueRowDTO
{
    public string $key;
    public string $label;
    public string $type;
    public string $cadenceKind;
    public ?int $cadenceNumber = null;
    public ?string $cadenceUnit = null;
    public string $cadenceLabel;
    public string $quantityLabel;
    public ?string $quantityValue = null;
    public ?string $quantityUnit = null;
    public ?string $unitTitle = null;
    public string $origin; // norm | personal — персональную (вне нормы) можно убрать из черновика
    /** Прятать количество/«на руках» — инструктаж (периодическая, норма 1 шт). Источник — {@see \App\Compliance\Application\DTO\ProfileCompliance\ObligationRowDTO::$hideQuantity}, не пересчитывается. */
    public bool $hideQuantity = false;
    /**
     * Показывать поле «Кол-во»/«Ед.» выдачи — строка несёт вводимое количество (материал или периодический
     * расходник) и не инструктаж. Вычислено ОДНИМ местом в {@see GetIssuePageQueryHandler::issueRow()} из
     * {@see \App\Compliance\Domain\Type\ComplianceType::requiresQuantity()} и {@see self::$hideQuantity} — твиг
     * предикат не пересчитывает.
     */
    public bool $showAmountInput = false;
    /** Предзаполнение количества выдачи — дефицитом (норма минус held, не ниже 0); null у позиций без нормы. */
    public ?string $deficitValue = null;
    // Черновика больше нет (прямая запись в журнал) — эти поля всегда null, форма падает на fallback
    // (documentDate/пустое значение); оставлены по месту их реального источника — не read-модель.
    public ?string $savedDate = null;
    public ?string $savedWear = null;
    public ?string $savedDueDate = null;
    public ?string $savedUnit = null;
    public ?string $savedNote = null;
    /** @var array<string, mixed> */
    public array $savedInstruction = [];
    public bool $selected = false;
    /** Канонический 4-бакет (ok/soon/overdue/missing) — тот же резолвер/ладдер, что у дашборда. */
    public string $bucket;
    public string $bucketLabel;
    public string $held;
    /** Цвет бейджа «на руках» (UI-нюанс сверх 4 бакетов — перевыдача сверх нормы отдельным цветом). */
    public string $heldTone;
    public ?string $lastFulfilledAt = null;
    public ?string $nextDueAt = null;
}
