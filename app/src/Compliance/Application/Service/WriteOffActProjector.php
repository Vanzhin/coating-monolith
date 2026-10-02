<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;

/**
 * RenderData для docx-акта списания: идентичность сотрудника + таблица списанных позиций (повтор строк) +
 * реквизиты/комиссия (если акт подписан — иначе пусто, заполняется при подписании). Строка — порция
 * {@see WriteOffItem}: наименование/единица — с факта-выдачи ({@see ProfileCompliance::recordById}), количество
 * и причина — с самой порции (один факт может быть списан несколькими порциями, в разных актах).
 */
final readonly class WriteOffActProjector
{
    public function project(WriteOffAct $act, ProfileDTO $employee): RenderData
    {
        $compliance = $act->profileCompliance();
        $items = [];
        foreach ($compliance->itemsOfWriteOffAct($act->getId()) as $portion) {
            $items[] = $this->rowOf($portion, $compliance);
        }

        $values = [
            'employee_fio' => new TextValue($this->fio($employee)),
            'items' => new RepeatValue($items),
        ];

        if (null !== $act->actNumber()) {
            $values['act_number'] = new TextValue($act->actNumber());
        }
        if (null !== $act->actDate()) {
            $values['act_date'] = new TextValue($act->actDate()->format('d.m.Y'));
        }

        $commission = $act->commission();
        if (null !== $commission) {
            $values['rep_position'] = new TextValue($commission->representative->position);
            $values['rep_fio'] = new TextValue($commission->representative->fio);
            $values['members_text'] = new TextValue(implode('; ', array_map(
                static fn (WriteOffCommissionMember $m): string => trim($m->position.' '.$m->fio),
                $commission->members,
            )));
        }

        return new RenderData($values);
    }

    /** @return array{name: string, qty: string, issue_date: string, reason: string} */
    private function rowOf(WriteOffItem $portion, ProfileCompliance $compliance): array
    {
        $fact = $compliance->recordById($portion->recordId());
        $unit = $fact?->quantity()?->unit->title() ?? '';

        return [
            'name' => null !== $fact ? $compliance->obligationLabelOf($fact->obligationKey()) : '',
            'qty' => '' !== $unit ? $this->number($portion->quantity()).' '.$unit : $this->number($portion->quantity()),
            'issue_date' => $fact?->fulfilledAt()->format('d.m.Y') ?? '',
            'reason' => $portion->reason()?->title() ?? '',
        ];
    }

    private function number(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function fio(ProfileDTO $employee): string
    {
        return trim(sprintf('%s %s %s', $employee->lastName, $employee->firstName, $employee->middleName ?? ''));
    }
}
