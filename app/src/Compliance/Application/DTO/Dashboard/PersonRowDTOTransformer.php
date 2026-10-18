<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection;
use App\Personnel\Application\DTO\Profile\ProfileDTO;

/**
 * Строит строку дашборда из строк проекции учёта (одного человека) + профиля (идентичность). Бакеты — на
 * чтении {@see ComplianceBucketResolver}. `$onlyType` сужает показанные обязанности (фильтр по типу).
 */
final readonly class PersonRowDTOTransformer
{
    public function __construct(private ComplianceBucketResolver $resolver)
    {
    }

    /**
     * @param list<ObligationProjection> $rows строки проекции ОДНОГО профиля ({@see ObligationProjection::profileId()} одинаков у всех)
     */
    public function fromRows(array $rows, ProfileDTO $profile, \DateTimeImmutable $now, ?ComplianceType $onlyType = null): PersonRowDTO
    {
        $row = new PersonRowDTO();
        $row->profileId = $profile->id;
        $row->fullName = trim($profile->lastName.' '.$profile->firstName.' '.($profile->middleName ?? ''));
        $row->initials = $this->initials($profile->lastName, $profile->firstName);
        $row->positionTitle = $profile->positionTitle;
        $row->departmentTitle = $profile->departmentTitle;
        $row->material = new BucketCountsDTO();
        $row->nonMaterial = new BucketCountsDTO();

        $groups = [];
        $worst = null;
        foreach ($rows as $obligation) {
            if (null !== $onlyType && $obligation->type() !== $onlyType) {
                continue;
            }
            $bucket = $this->resolver->bucketFor($obligation, $now);
            $worst = null === $worst ? $bucket : ComplianceBucket::worseOf($worst, $bucket);
            (ComplianceType::Material === $obligation->type() ? $row->material : $row->nonMaterial)->add($bucket);

            $requirementId = $obligation->requirementId();
            if (!isset($groups[$requirementId])) {
                $groups[$requirementId] = $this->group($obligation);
            }
            $group = $groups[$requirementId];
            $group->rows[] = $this->rowOf($obligation, $bucket);
            $group->counts->add($bucket);
        }

        $row->groups = array_values($groups);
        $row->worstBucket = ($worst ?? ComplianceBucket::Ok)->value;

        return $row;
    }

    private function group(ObligationProjection $obligation): RequirementGroupDTO
    {
        $group = new RequirementGroupDTO();
        $group->requirementId = $obligation->requirementId();
        $group->name = $obligation->requirementName() ?? '';
        $group->type = $obligation->type()->value;
        $group->typeLabel = $obligation->type()->title();
        $group->counts = new BucketCountsDTO();

        return $group;
    }

    private function rowOf(ObligationProjection $obligation, ComplianceBucket $bucket): ObligationBucketRowDTO
    {
        $row = new ObligationBucketRowDTO();
        $row->label = $obligation->label();
        $row->spec = $this->spec($obligation);
        $row->lastFulfilledAt = $obligation->lastFulfilledAt()?->format('Y-m-d');
        $row->nextDueAt = $obligation->nextDueAt()?->format('Y-m-d');
        $row->bucket = $bucket->value;
        $row->bucketLabel = $bucket->label($obligation->type());

        return $row;
    }

    /** «5 пара · ежегодно» — норма (сумма+единица) + периодичность; нематериальная позиция — только периодичность. */
    private function spec(ObligationProjection $obligation): string
    {
        $quantity = $this->quantityLabel($obligation);
        $cadence = $obligation->cadence(); // уже готовый label-снимок, см. ObligationProjection::cadence()

        return null !== $quantity ? $quantity.' · '.$cadence : $cadence;
    }

    /** Норма «5 пара» — сумма ({@see ObligationProjection::quantity()}) + единица ({@see ObligationProjection::quantityUnit()}). */
    private function quantityLabel(ObligationProjection $obligation): ?string
    {
        $amount = $obligation->quantity();
        $unit = $obligation->quantityUnit();
        if (null === $amount || null === $unit) {
            return null;
        }
        $formatted = 0.0 === fmod($amount, 1.0) ? (string) (int) $amount : (string) $amount;

        return $formatted.' '.$unit->title();
    }

    private function initials(string $lastName, string $firstName): string
    {
        return mb_strtoupper(mb_substr($lastName, 0, 1).mb_substr($firstName, 0, 1));
    }
}
