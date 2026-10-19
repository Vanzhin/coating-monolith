<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

use App\Compliance\Application\DTO\ProfileCompliance\ObligationRowDTO;
use App\Compliance\Application\Service\AmountFormatter;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\ValueObject\Unit;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Собирает read-модель формы выдачи `/issue`: позиции текущей нормы (бейдж/норма/на руках/сроки — EAGER), схему
 * полей инструктажа, доступность и черновик списания, № карточки. Obligation-строки (даты/бакет/на руках) — из
 * read-модели {@see GetProfileComplianceQuery} (проекция, не агрегат — «на руках» периодической там уже ПОСЛЕДНЯЯ
 * выдача, не Σ, см. {@see \App\Compliance\Domain\ReadModel\ObligationProjection::heldQuantity()}); агрегат
 * {@see ProfileComplianceRepositoryInterface} грузится ТОЛЬКО для `recordsForRequirement`/`cardNumber` — этих
 * данных в проекции нет.
 * Presentation-merge текущего HTTP-запроса (восстановление формы после ошибки сабмита — `savedInstruction`/
 * `selected`) сюда НЕ входит: это не read-модель, а состояние конкретного запроса, его накладывает
 * {@see \App\Compliance\Infrastructure\Controller\Fulfillment\IssueAction} после получения страницы.
 */
final readonly class GetIssuePageQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private ProfileComplianceRepositoryInterface $repository,
        private RequirementRepositoryInterface $requirements,
        private WriteOffActRepositoryInterface $writeOffActRepository,
    ) {
    }

    public function __invoke(GetIssuePageQuery $query): ?IssuePageDTO
    {
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($query->profileId));
        if (null === $profileResult->profile) {
            return null;
        }

        $page = new IssuePageDTO();
        $page->profile = $profileResult->profile;

        $profileCompliance = $this->repository->findByProfile($query->profileId);

        /** @var GetProfileComplianceQueryResult $complianceResult */
        $complianceResult = $this->queryBus->execute(new GetProfileComplianceQuery($query->profileId));
        $obligations = null !== $complianceResult->compliance ? $complianceResult->compliance->obligations : [];

        $requirementType = null; // тип акта (мономорфен) — из первой строки проекции этого требования
        foreach ($obligations as $row) {
            if ($row->requirementId !== $query->requirementId) {
                continue;
            }
            $page->requirementName = $row->requirementName;
            $requirementType ??= $row->type;
            $page->rows[] = $this->issueRow($row);
        }
        $page->requirementType = $requirementType ?? 'material';

        // Сортировка позиций по срочности для удобства выдачи: сверху требующие внимания (просрочено/не
        // выдано), затем «скоро срок», затем «в норме». usort в PHP 8 стабилен → внутри группы сохраняется
        // порядок нормы, а индексы полей формы items[i] не «прыгают» между сабмитом и повторным рендером при
        // ошибке (ничего не сохранилось → бакеты те же → порядок тот же).
        $bucketOrder = ['overdue' => 0, 'missing' => 0, 'soon' => 1, 'ok' => 2];
        usort($page->rows, static fn (IssueRowDTO $a, IssueRowDTO $b): int => ($bucketOrder[$a->bucket] ?? 9) <=> ($bucketOrder[$b->bucket] ?? 9));

        // Схема полей инструктажа (для не материального требования с видом журнала) — форма рисует поля по ней,
        // сгруппировав по FieldSpec::group (напр. «Теоретическая часть» / «Практическая часть»).
        $journalKind = $this->requirements->findOneById($query->requirementId)?->getJournalKind();
        foreach (null !== $journalKind ? $journalKind->fields() : [] as $field) {
            $fieldView = new IssueInstructionFieldDTO();
            $fieldView->key = $field->key;
            $fieldView->label = $field->label;
            $fieldView->kind = $field->kind->value;
            $fieldView->required = $field->required;
            $fieldView->options = $field->options;

            $lastIndex = array_key_last($page->instructionGroups);
            $last = null !== $lastIndex ? $page->instructionGroups[$lastIndex] : null;
            if (null !== $last && $last->label === $field->group) {
                $last->fields[] = $fieldView;
            } else {
                $group = new IssueInstructionGroupDTO();
                $group->label = $field->group;
                $group->fields[] = $fieldView;
                $page->instructionGroups[] = $group;
            }
        }

        $page->cardNumber = $profileCompliance?->cardNumber();

        // Списание — ПАРАЛЛЕЛЬНЫЙ вход, доступный на той же странице (не взаимоисключающий с выдачей по статусу).
        // «Списать» показываем, когда есть хотя бы один материальный факт требования на руках; разбивка по
        // позициям (что и когда выдано/списано) — в единой карточке выше + её ленивой истории, здесь не дублируем.
        foreach ($profileCompliance?->recordsForRequirement($query->requirementId) ?? [] as $record) {
            if (null !== $record->quantity() && $record->heldAmount() > 0.0) {
                $page->canWriteOff = true;
                break;
            }
        }

        // Акт списания — самостоятельный кросс-персон агрегат, корзина одна на пару (требование × организация).
        // В секции показываем открытый черновик (его состав уточняют/оформляют на странице акта; оформленные акты
        // доступны ссылкой из истории позиции). Достаточно id/флага/числа строк, без резолва меток.
        $draft = $this->writeOffActRepository->findOpenDraft($query->requirementId, $profileResult->profile->organizationId);
        if (null !== $draft) {
            $page->writeOffActs[] = new IssueWriteOffActSummaryDTO($draft->getId(), false, count($draft->lines()));
        }

        return $page;
    }

    private function issueRow(ObligationRowDTO $row): IssueRowDTO
    {
        $norm = null !== $row->quantityValue ? (float) $row->quantityValue : null;
        $held = $row->heldQuantity; // проекция — у периодической это ПОСЛЕДНЯЯ выдача, не пересчитываем

        $issueRow = new IssueRowDTO();
        $issueRow->key = $row->key;
        $issueRow->label = $row->label;
        $issueRow->type = $row->type;
        $issueRow->cadenceKind = $row->cadenceKind;
        $issueRow->cadenceNumber = $row->cadenceNumber;
        $issueRow->cadenceUnit = $row->cadenceUnit;
        $issueRow->cadenceLabel = $row->cadenceLabel;
        $issueRow->quantityLabel = $row->quantityLabel;
        $issueRow->quantityValue = $row->quantityValue;
        $issueRow->quantityUnit = $row->quantityUnit;
        $issueRow->unitTitle = null !== $row->quantityUnit ? Unit::from($row->quantityUnit)->title() : null;
        $issueRow->origin = $row->origin; // personal (вне нормы) → в форме можно удалить из черновика
        $issueRow->hideQuantity = $row->hideQuantity;
        // Предзаполнение количества выдачи — дефицитом (норма минус то, что уже на руках, но не ниже 0);
        // при полной норме дефицит = 0, поле остаётся редактируемым (замена сверх нормы).
        $issueRow->deficitValue = null !== $row->quantityValue ? AmountFormatter::trimmed(max(0.0, (float) $row->quantityValue - $held)) : null;
        // Единая карточка: статус-бейдж (тот же резолвер/бакет, что у дашборда), «на руках N/норма», посл.
        // выдача/след. срок — EAGER, видны без раскрытия ленивой истории.
        $issueRow->bucket = $row->bucket;
        $issueRow->bucketLabel = $row->bucketLabel;
        $issueRow->held = AmountFormatter::trimmed($held);
        $issueRow->heldTone = $this->heldTone($held, $norm);
        $issueRow->lastFulfilledAt = $this->formatDate($row->lastFulfilledAt);
        $issueRow->nextDueAt = $this->formatDate($row->nextDueAt);

        return $issueRow;
    }

    /**
     * Цвет чипа «на руках» относительно нормы (как подсветка кол-ва в оформлении): меньше — danger, ровно —
     * success, больше — info. Нюанс сверх 4 бакетов (перевыдача сверх нормы — отдельный цвет, бакет её не
     * различает от «ok»), поэтому не сводится к {@see ComplianceBucketResolver::bucketFor()}; порог/эпсилон —
     * ОТТУДА же, не второй хардкод.
     */
    private function heldTone(float $held, ?float $norm): string
    {
        if (null === $norm) {
            return 'secondary';
        }
        if ($held < $norm - ComplianceBucketResolver::QUANTITY_EPSILON) {
            return 'danger';
        }
        if ($held > $norm + ComplianceBucketResolver::QUANTITY_EPSILON) {
            return 'info';
        }

        return 'success';
    }

    /** Y-m-d (read-model) → d.m.Y (отображение в карточке); null проходит как null. */
    private function formatDate(?string $ymd): ?string
    {
        if (null === $ymd) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd)?->format('d.m.Y');
    }
}
