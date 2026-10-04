<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\RequirementDocument;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;

/**
 * {@see RenderData} для вьюхи личной карточки СИЗ (docx/xlsx): идентичность сотрудника (ФИО по частям, пол,
 * рост, размеры, табельный, даты) + вся норма требования (не дефицит — карточка показывает что положено) +
 * № карточки и ответственное лицо с последнего подписанного акта выдачи. Форматирование — здесь.
 */
final readonly class RequirementCardProjector
{
    public function project(ProfileCompliance $profileCompliance, ProfileDTO $profile, Requirement $requirement, \DateTimeImmutable $now): RenderData
    {
        $basisByLabel = [];
        foreach ($requirement->getItems() as $item) {
            $basisByLabel[mb_strtolower(trim($item->label()))] = $item->basis();
        }

        $rows = [];
        foreach ($profileCompliance->getObligations() as $obligation) {
            if ($obligation->requirementId() !== $requirement->getId()) {
                continue;
            }
            $quantity = $obligation->quantity();
            $unit = $quantity?->unit->title() ?? '';
            $cadence = $obligation->cadence()->label();
            $rows[] = [
                'label' => $obligation->label(),
                'basis' => $basisByLabel[mb_strtolower(trim($obligation->label()))] ?? '',
                'unit' => $unit,
                'cadence' => $cadence,
                'unit_cadence' => trim($unit.('' !== $unit && '' !== $cadence ? ', ' : '').$cadence),
                'quantity' => null !== $quantity ? $this->number($quantity->amount) : '',
                'issue_date' => $now->format('d.m.Y'),
                'limit_date' => $obligation->cadence()->nextDueFrom($now)?->format('d.m.Y') ?? '',
            ];
        }

        $values = [
            'employee_fio' => new TextValue($this->fio($profile)),
            'employee_last_name' => new TextValue($profile->lastName),
            'employee_first_name' => new TextValue($profile->firstName),
            'position_title' => new TextValue($profile->positionTitle),
            'org_title' => new TextValue($profile->organizationTitle),
            'department_title' => new TextValue($profile->departmentTitle),
            'document_date' => new TextValue($now->format('d.m.Y')),
            'items' => new RepeatValue($rows),
        ];

        $this->optional($values, 'employee_middle_name', $profile->middleName);
        $this->optional($values, 'gender', $profile->gender);
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

        // № карточки и ответственное лицо — с последнего подписанного акта выдачи; всегда отдаём ключ (пусто,
        // если выдач ещё не было), чтобы строгий плейсхолдер {{card_number}} не падал на нераспечатанной карточке.
        $latest = $this->latestSigned($profileCompliance, $requirement->getId());
        $values['card_number'] = new TextValue($latest?->actNumber() ?? '');
        $values['responsible_fio'] = new TextValue($latest?->responsibleFio() ?? '');

        return new RenderData($values);
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
