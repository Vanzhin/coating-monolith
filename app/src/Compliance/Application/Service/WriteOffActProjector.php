<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffAct;
use App\Compliance\Domain\Aggregate\ProfileCompliance\WriteOffItem;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\ValueObject\CommissionMember;

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

        $members = $act->commission()->members ?? [];

        // Все ключи отдаём ВСЕГДА (пустыми, если не заполнено) — так и строгий {{x}}, и {{x?}} не падают
        // на незаполненном черновике. Повторяемые группы (items/commission) — списком (пустой → строка удалится).
        $values = [
            'employee_fio' => new TextValue($this->fio($employee)),
            'org_title' => new TextValue($employee->organizationTitle),
            'act_number' => new TextValue($act->actNumber() ?? ''),
            'act_date' => new TextValue($act->actDate()?->format('d.m.Y') ?? ''),
            'order_number' => new TextValue($act->orderNumber() ?? ''),
            'order_date' => new TextValue($act->orderDate()?->format('d.m.Y') ?? ''),
            'representative_position' => new TextValue($act->representativePosition() ?? ''),
            'representative_fio' => new TextValue($act->representativeFio() ?? ''),
            'items' => new RepeatValue($items),
            'commission' => new RepeatValue(array_map(
                static fn (CommissionMember $m): array => [
                    'organization' => $m->organization,
                    'position' => $m->position,
                    'fio' => $m->fio,
                    'date' => $m->date,
                ],
                $members,
            )),
            'members_text' => new TextValue(implode('; ', array_map(
                static fn (CommissionMember $m): string => trim($m->position.' '.$m->fio),
                $members,
            ))),
        ];

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
