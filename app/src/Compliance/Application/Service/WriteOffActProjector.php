<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;

/**
 * RenderData для docx-акта списания: идентичность сотрудника + таблица списанных позиций (повтор строк) +
 * реквизиты/комиссия (если акт подписан — иначе пусто, заполняется при подписании). Причина — на строку
 * (позицию), берётся с самого item-факта.
 */
final readonly class WriteOffActProjector
{
    public function project(WriteOffAct $act, ProfileDTO $employee): RenderData
    {
        $compliance = $act->profileCompliance();
        $items = array_map(static fn (FulfillmentRecord $fact): array => [
            'name' => $compliance->obligationLabelOf($fact->obligationKey()),
            'qty' => $fact->quantity()?->label() ?? '',
            'issue_date' => $fact->fulfilledAt()->format('d.m.Y'),
            'reason' => $fact->writeOffReason()?->title() ?? '',
        ], $compliance->itemsOfWriteOffAct($act->getId()));

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

    private function fio(ProfileDTO $employee): string
    {
        return trim(sprintf('%s %s %s', $employee->lastName, $employee->firstName, $employee->middleName ?? ''));
    }
}
