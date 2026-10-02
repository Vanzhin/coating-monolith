<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Application\ReadModel\ComplianceBucketResolver;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;

/**
 * Собирает {@see RenderData} для бланка карточки СИЗ: идентичность сотрудника + таблица подошедших позиций
 * требования (статус ≠ ок) с количеством из нормы, датой выдачи = сегодня и расчётным «срок до». «Пункт норм»
 * (основание) берётся из позиций требования по названию. Форматирование значений — здесь (в движок уходят строки).
 */
final readonly class RequirementCardProjector
{
    public function __construct(private ComplianceBucketResolver $buckets)
    {
    }

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
            $bucket = $this->buckets->bucketFor($obligation->isActive(), $obligation->lastFulfilledAt(), $obligation->nextDueAt(), $now);
            if (ComplianceBucket::Ok === $bucket) {
                continue; // на бланк — только то, что надо выдать
            }
            $quantity = $obligation->quantity();
            $rows[] = [
                'label' => $obligation->label(),
                'basis' => $basisByLabel[mb_strtolower(trim($obligation->label()))] ?? '',
                'unit' => $quantity?->unit->title() ?? '',
                'cadence' => $obligation->cadence()->label(),
                'quantity' => null !== $quantity ? $this->number($quantity->amount) : '',
                'issue_date' => $now->format('d.m.Y'),
                'limit_date' => $obligation->cadence()->nextDueFrom($now)?->format('d.m.Y') ?? '',
            ];
        }

        $values = [
            'employee_fio' => new TextValue($this->fio($profile)),
            'position_title' => new TextValue($profile->positionTitle),
            'org_title' => new TextValue($profile->organizationTitle),
            'department_title' => new TextValue($profile->departmentTitle),
            'document_date' => new TextValue($now->format('d.m.Y')),
            'items' => new RepeatValue($rows),
        ];
        if (null !== $profile->personnelNumber && '' !== $profile->personnelNumber) {
            $values['personnel_number'] = new TextValue($profile->personnelNumber);
        }

        return new RenderData($values);
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
