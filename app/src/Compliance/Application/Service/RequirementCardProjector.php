<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\RequirementDocument;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;

/**
 * {@see RenderData} для личной карточки СИЗ (docx/xlsx). Две части:
 *  - стр. 1 ({{items.*}}) — НОРМА требования: весь перечень положенного (наименование/основание/норм-количество/
 *    периодичность), не зависит от выдач;
 *  - стр. 2+ ({{log.*}}) — ФАКТ: позиции ЭТОГО акта (открытый черновик ?? последний подписанный) с моделью/маркой,
 *    датой и количеством выдачи + возврат/акт списания (джойн по recordId из подписанных актов списания).
 * Акт = слепок одной выдачи (новая выдача = новый акт), списание лишь дозаполняет колонки возврата — это не
 * сквозной лог. Идентичность сотрудника, № карточки и ответственное лицо — из того же акта-источника.
 * Форматирование — здесь.
 */
final readonly class RequirementCardProjector
{
    public function project(ProfileCompliance $profileCompliance, ProfileDTO $profile, Requirement $requirement, \DateTimeImmutable $now): RenderData
    {
        $obligationByKey = [];
        foreach ($profileCompliance->getObligations() as $obligation) {
            if ($obligation->requirementId() === $requirement->getId()) {
                $obligationByKey[$obligation->key()] = $obligation;
            }
        }

        // Источник факт-части и реквизитов бланка — ЭТОТ акт: открытый черновик, иначе последний подписанный.
        $source = $profileCompliance->openDraftFor($requirement->getId())
            ?? $this->latestSigned($profileCompliance, $requirement->getId());

        $values = [
            'employee_fio' => new TextValue($this->fio($profile)),
            'employee_last_name' => new TextValue($profile->lastName),
            'employee_first_name' => new TextValue($profile->firstName),
            'position_title' => new TextValue($profile->positionTitle),
            'org_title' => new TextValue($profile->organizationTitle),
            'department_title' => new TextValue($profile->departmentTitle),
            'document_date' => new TextValue($now->format('d.m.Y')),
            'items' => new RepeatValue($this->normItems($requirement)),
            'log' => new RepeatValue(null !== $source ? $this->factLog($profileCompliance, $source, $requirement->getId(), $obligationByKey) : []),
        ];

        $this->optional($values, 'employee_middle_name', $profile->middleName);
        $this->optional($values, 'gender', $profile->gender?->label);
        $this->optional($values, 'height', $profile->height);
        $this->optional($values, 'size_clothing', $profile->clothing);
        $this->optional($values, 'size_shoes', $profile->shoes);
        $this->optional($values, 'size_headgear', $profile->headgear);
        $this->optional($values, 'size_respirator', $profile->respirator);
        $this->optional($values, 'size_gloves', $profile->gloves);
        $this->optional($values, 'personnel_number', $profile->personnelNumber);
        if (null !== $profile->hiredAt) {
            $hired = $profile->hiredAt->format('d.m.Y');
            $values['hired_at'] = new TextValue($hired);
            $values['position_change_date'] = new TextValue($hired); // дубль даты приёма (пока так)
        }

        // № карточки и ответственное лицо — из того же акта ($source). Всегда отдаём ключ (пусто, если ещё
        // не заполнено), чтобы строгий плейсхолдер {{card_number}} не падал на нераспечатанной карточке.
        $values['card_number'] = new TextValue($source?->actNumber() ?? '');
        $values['responsible_fio'] = new TextValue($source?->responsibleFio() ?? '');

        return new RenderData($values);
    }

    /**
     * Стр. 1 — норма требования: наименование/основание/норм-количество/периодичность. Для нематериальной
     * позиции количество пустое.
     *
     * @return list<array<string, string>>
     */
    private function normItems(Requirement $requirement): array
    {
        $rows = [];
        foreach ($requirement->getItems() as $item) {
            $quantity = $item instanceof MaterialItem ? $item->quantity() : null;
            $unit = $quantity?->unit->title() ?? '';
            $cadence = $item->cadence()->label();
            $rows[] = [
                'label' => $item->label(),
                'basis' => $item->basis(),
                'unit' => $unit,
                'cadence' => $cadence,
                'unit_cadence' => trim($unit.('' !== $unit && '' !== $cadence ? ', ' : '').$cadence),
                'quantity' => null !== $quantity ? $this->number($quantity->amount) : '',
            ];
        }

        return $rows;
    }

    /**
     * Стр. 2 — записи ЭТОГО акта как строки факт-таблицы + возвраты/списания по recordId. Сортировка по наименованию.
     *
     * @param array<string, TrackedObligation> $obligationByKey
     *
     * @return list<array<string, string>>
     */
    private function factLog(ProfileCompliance $profileCompliance, RequirementDocument $source, string $requirementId, array $obligationByKey): array
    {
        $returnsByRecord = $this->returnsByRecord($profileCompliance, $requirementId);

        $rows = [];
        foreach ($profileCompliance->recordsForRequirement($requirementId) as $record) {
            if ($record->documentId() !== $source->getId()) {
                continue; // строка другого акта
            }
            $obligation = $obligationByKey[$record->obligationKey()] ?? null;
            $label = null !== $obligation ? $obligation->label() : $profileCompliance->obligationLabelOf($record->obligationKey());
            $quantity = $record->quantity();
            $returns = $returnsByRecord[$record->getId()] ?? [];

            $returnedQty = 0.0;
            $returnDates = [];
            $acts = [];
            foreach ($returns as $return) {
                $returnedQty += $return['qty'];
                $returnDates[] = $return['date'];
                $acts[] = $return['act'];
            }

            $rows[] = [
                'name' => $label,
                'model' => $record->note() ?? '',
                'issue_date' => $record->fulfilledAt()->format('d.m.Y'),
                'issue_qty' => null !== $quantity ? $this->number($quantity->amount) : '',
                'return_date' => implode('; ', $returnDates),
                'return_qty' => [] !== $returns ? $this->number($returnedQty) : '',
                'writeoff_act' => implode('; ', $acts),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Возвраты по recordId из ПОДПИСАННЫХ актов списания требования: количество → «возвращено», дата и
     * «№ … от …» → колонки возврата/акта (несколько списаний на запись — все, сворачиваются через «; »).
     *
     * @return array<string, list<array{qty: float, date: string, act: string}>>
     */
    private function returnsByRecord(ProfileCompliance $profileCompliance, string $requirementId): array
    {
        $byRecord = [];
        foreach ($profileCompliance->getWriteOffActs() as $act) {
            if ($act->requirementId() !== $requirementId || !$act->isSigned()) {
                continue;
            }
            $date = $act->actDate()?->format('d.m.Y') ?? '';
            $actLabel = $this->actLabel((string) $act->actNumber(), $date);
            foreach ($profileCompliance->itemsOfWriteOffAct($act->getId()) as $portion) {
                $byRecord[$portion->recordId()][] = [
                    'qty' => $portion->quantity(),
                    'date' => $date,
                    'act' => $actLabel,
                ];
            }
        }

        return $byRecord;
    }

    /** «№ N от DD.MM.YYYY», опуская пустые части: нет номера → «от DD.MM.YYYY», нет даты → «№ N». */
    private function actLabel(string $number, string $date): string
    {
        $parts = [];
        if ('' !== $number) {
            $parts[] = '№ '.$number;
        }
        if ('' !== $date) {
            $parts[] = 'от '.$date;
        }

        return implode(' ', $parts);
    }

    private function latestSigned(ProfileCompliance $profileCompliance, string $requirementId): ?RequirementDocument
    {
        $latest = null;
        foreach ($profileCompliance->signedDocumentsFor($requirementId) as $document) {
            if (null === $latest || $document->signedAt() > $latest->signedAt()) {
                $latest = $document;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, RepeatValue|TextValue> $values
     */
    private function optional(array &$values, string $key, ?string $value): void
    {
        if (null !== $value && '' !== trim($value)) {
            $values[$key] = new TextValue($value);
        }
    }

    private function fio(ProfileDTO $profile): string
    {
        return trim(sprintf('%s %s %s', $profile->lastName, $profile->firstName, $profile->middleName ?? ''));
    }

    private function number(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }
}
