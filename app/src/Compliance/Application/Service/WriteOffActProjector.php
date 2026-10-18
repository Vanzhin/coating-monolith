<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffActLine;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\ValueObject\CommissionMember;

/**
 * RenderData для docx-акта списания самостоятельного кросс-персон агрегата {@see WriteOffAct}: таблица
 * списанных позиций (повтор строк) + реквизиты/комиссия. Строка — порция {@see WriteOffActLine}: наименование
 * берём из снимка строки ({@see WriteOffActLine::labelSnapshot}), единицу/дату выдачи — с факта-выдачи
 * (резолвит контроллер, передаёт `$recordsById`). Идентичность в шапке — сведённая по всем людям акта
 * (`$profilesById`): акт один, может покрывать позиции нескольких сотрудников одной организации.
 */
final readonly class WriteOffActProjector
{
    /**
     * @param array<string, ProfileDTO>        $profilesById профили людей акта по profileId (для шапки)
     * @param array<string, FulfillmentRecord> $recordsById  факты-выдачи строк акта по recordId (единица/дата)
     */
    public function project(WriteOffAct $act, array $profilesById, array $recordsById): RenderData
    {
        // Строки сгруппированы по человеку (все строки p1, затем p2…) и каждая несёт ФИО своего человека —
        // так плоский шаблон (повтор по `items`) рендерит акт с людьми и держит их строки рядом.
        $items = [];
        foreach ($act->linesByProfile() as $profileId => $lines) {
            $fio = isset($profilesById[$profileId]) ? $profilesById[$profileId]->fio() : '';
            foreach ($lines as $line) {
                $items[] = $this->rowOf($line, $recordsById, $fio);
            }
        }

        $commission = $act->commission();
        $members = null !== $commission ? $commission->members : [];

        // Все ключи отдаём ВСЕГДА (пустыми, если не заполнено) — так и строгий {{x}}, и {{x?}} не падают
        // на незаполненном черновике. Повторяемые группы (items/commission) — списком (пустой → строка удалится).
        $values = [
            'employee_fio' => new TextValue($this->employeesFio($act, $profilesById)),
            'org_title' => new TextValue($this->orgTitle($act, $profilesById)),
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

    /**
     * @param array<string, FulfillmentRecord> $recordsById
     *
     * @return array{name: string, qty: string, issue_date: string, reason: string, employee_fio: string}
     */
    private function rowOf(WriteOffActLine $line, array $recordsById, string $employeeFio): array
    {
        $fact = $recordsById[$line->recordId()] ?? null;
        $unit = $fact?->quantity()?->unit->title() ?? '';

        return [
            'name' => $line->labelSnapshot(),
            'qty' => '' !== $unit ? $this->number($line->quantity()).' '.$unit : $this->number($line->quantity()),
            'issue_date' => $fact?->fulfilledAt()->format('d.m.Y') ?? '',
            'reason' => $line->reason()?->title() ?? '',
            'employee_fio' => $employeeFio,
        ];
    }

    /** @param array<string, ProfileDTO> $profilesById */
    private function employeesFio(WriteOffAct $act, array $profilesById): string
    {
        $names = [];
        foreach ($act->profileIds() as $profileId) {
            $profile = $profilesById[$profileId] ?? null;
            if (null !== $profile) {
                $names[] = $profile->fio();
            }
        }

        return implode('; ', $names);
    }

    /** @param array<string, ProfileDTO> $profilesById Организация одна на акт (org-scoped) — берём у первого профиля. */
    private function orgTitle(WriteOffAct $act, array $profilesById): string
    {
        foreach ($act->profileIds() as $profileId) {
            $profile = $profilesById[$profileId] ?? null;
            if (null !== $profile) {
                return $profile->organizationTitle;
            }
        }

        return '';
    }

    private function number(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }
}
