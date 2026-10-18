<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;

/**
 * {@see RenderData} для личной карточки СИЗ (docx/xlsx). Две части:
 *  - стр. 1 ({{items.*}}) — НОРМА требования: весь перечень положенного (наименование/основание/норм-количество/
 *    периодичность), не зависит от выдач;
 *  - стр. 2+ ({{log.*}}) — ФАКТ: ВЕСЬ журнал фактов требования (не одного акта) с моделью/маркой, датой и
 *    количеством выдачи + возврат/акт списания (джойн по recordId из подписанных актов списания). Журнал —
 *    сквозной источник истины, печать больше не привязана к конкретному акту-документу.
 * № карточки — свойство журнала {@see ProfileCompliance::cardNumber()}
 * (одна карточка на человека, закрепляется при первой выдаче). Ответственное лицо — реквизит акта-документа,
 * которого у прямой журнальной записи нет; оставлено пустым (плейсхолдер {{responsible_fio}} всегда получает
 * ключ, чтобы строгий рендер не падал). Форматирование — здесь.
 */
final readonly class RequirementCardProjector
{
    public function __construct(
        private WriteOffActRepositoryInterface $writeOffActRepository,
        private ObligationProjectionRepositoryInterface $projectionRepository,
    ) {
    }

    public function project(ProfileCompliance $profileCompliance, ProfileDTO $profile, Requirement $requirement, \DateTimeImmutable $now): RenderData
    {
        $obligationByKey = [];
        foreach ($this->projectionRepository->findForProfiles(new StringCollection($profileCompliance->getProfileId())) as $obligation) {
            if ($obligation->requirementId() === $requirement->getId()) {
                $obligationByKey[$obligation->key()] = $obligation;
            }
        }

        $values = [
            'employee_fio' => new TextValue($this->fio($profile)),
            'employee_last_name' => new TextValue($profile->lastName),
            'employee_first_name' => new TextValue($profile->firstName),
            'position_title' => new TextValue($profile->positionTitle),
            'org_title' => new TextValue($profile->organizationTitle),
            'department_title' => new TextValue($profile->departmentTitle),
            'document_date' => new TextValue($now->format('d.m.Y')),
            'items' => new RepeatValue($this->normItems($requirement)),
            'log' => new RepeatValue($this->logRows($profileCompliance, $requirement, $obligationByKey, $profile)),
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

        // № карточки — свойство журнала (закреплён при первой выдаче); ответственное лицо — реквизит
        // акта-документа, у журнальной записи его нет. Ключ всегда отдаём (пусто), чтобы строгий плейсхолдер
        // {{responsible_fio}} не падал на нераспечатанной карточке.
        $values['card_number'] = new TextValue($profileCompliance->cardNumber() ?? '');
        $values['responsible_fio'] = new TextValue('');

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
     * Строки факт-таблицы/журнала: ВЕСЬ журнал фактов требования (+ возвраты/списания по recordId) с добавленным
     * контекстом сотрудника в каждую строку (колонка «инструктируемый» в журналах — построчно). Нет фактов → пусто.
     *
     * @param array<string, ObligationProjection> $obligationByKey
     *
     * @return list<array<string, string>>
     */
    private function logRows(ProfileCompliance $profileCompliance, Requirement $requirement, array $obligationByKey, ProfileDTO $profile): array
    {
        $basisByLabel = [];
        foreach ($requirement->getItems() as $item) {
            $basisByLabel[$item->label()] = $item->basis();
        }
        $rows = $this->factLog($profileCompliance, $requirement, $obligationByKey);
        foreach ($rows as $i => $row) {
            $rows[$i]['employee_fio'] = $this->fio($profile);
            $rows[$i]['employee_position'] = $profile->positionTitle;
            $rows[$i]['basis'] = $basisByLabel[$row['name']] ?? ''; // основание требования (напр. перечень локальных актов)
            $rows[$i]['birth_date'] = $profile->birthDate?->format('d.m.Y') ?? ''; // из карточки сотрудника, если заполнена
        }

        return $rows;
    }

    /**
     * Стр. 2 — ВЕСЬ журнал фактов требования как строки факт-таблицы + возвраты/списания по recordId. Сортировка
     * по наименованию.
     *
     * @param array<string, ObligationProjection> $obligationByKey
     *
     * @return list<array<string, string>>
     */
    private function factLog(ProfileCompliance $profileCompliance, Requirement $requirement, array $obligationByKey): array
    {
        $returnsByRecord = $this->returnsByRecord($profileCompliance, $requirement->getId());

        $rows = [];
        foreach ($profileCompliance->recordsForRequirement($requirement->getId()) as $record) {
            $obligation = $obligationByKey[$record->obligationKey()] ?? null;
            $label = null !== $obligation ? $obligation->label() : $profileCompliance->obligationLabelOf($requirement, $record->obligationKey());
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

            $row = [
                'name' => $label,
                'model' => $record->note() ?? '',
                'issue_date' => $record->fulfilledAt()->format('d.m.Y'),
                'issue_qty' => null !== $quantity ? $this->number($quantity->amount) : '',
                'return_date' => implode('; ', $returnDates),
                'return_qty' => [] !== $returns ? $this->number($returnedQty) : '',
                'writeoff_act' => implode('; ', $acts),
            ];
            // Поля инструктажа (не материальный журнал) — плоскими ключами в строку: {{log.instruction_kind}} и т.п.
            $details = $record->instructionDetails();
            if (null !== $details) {
                foreach ($details->values as $fieldKey => $value) {
                    $row[$fieldKey] = is_array($value) ? implode("\n", $value) : $value;
                }
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Возвраты по recordId из ПОДПИСАННЫХ актов списания требования, содержащих строки этого человека
     * (самостоятельный кросс-персон агрегат {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}):
     * количество → «возвращено», дата и «№ … от …» → колонки возврата/акта (несколько списаний на запись —
     * все, сворачиваются через «; »).
     *
     * @return array<string, list<array{qty: float, date: string, act: string}>>
     */
    private function returnsByRecord(ProfileCompliance $profileCompliance, string $requirementId): array
    {
        $profileId = $profileCompliance->getProfileId();
        $byRecord = [];
        foreach ($this->writeOffActRepository->findSignedForProfileRequirement($profileId, $requirementId) as $act) {
            $date = $act->actDate()?->format('d.m.Y') ?? '';
            $actLabel = $this->actLabel((string) $act->actNumber(), $date);
            foreach ($act->lines() as $line) {
                if ($line->profileId() !== $profileId) {
                    continue; // строка другого профиля того же кросс-персон акта
                }
                $byRecord[$line->recordId()][] = [
                    'qty' => $line->quantity(),
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
