<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Application\ReadModel\ComplianceBucketResolver;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Type\ComplianceType;
use App\Personnel\Application\DTO\Profile\ProfileDTO;

/**
 * Строит строку дашборда из проекции учёта + профиля (идентичность). Бакеты — на чтении
 * {@see ComplianceBucketResolver}. `$onlyType` сужает показанные обязанности (фильтр по типу).
 */
final readonly class PersonRowDTOTransformer
{
    public function __construct(private ComplianceBucketResolver $resolver)
    {
    }

    public function fromEntity(ProfileCompliance $pc, ProfileDTO $profile, \DateTimeImmutable $now, ?ComplianceType $onlyType = null): PersonRowDTO
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
        foreach ($pc->getObligations() as $obligation) {
            if (null !== $onlyType && $obligation->type() !== $onlyType) {
                continue;
            }
            $bucket = $this->resolver->bucketFor($obligation->isActive(), $obligation->lastFulfilledAt(), $obligation->nextDueAt(), $now, $obligation->quantity()?->amount, $obligation->heldQuantity());
            $worst = null === $worst ? $bucket : ComplianceBucket::worseOf($worst, $bucket);
            (ComplianceType::Material === $obligation->type() ? $row->material : $row->nonMaterial)->add($bucket);

            $requirementId = $obligation->requirementId();
            if (!isset($groups[$requirementId])) {
                $groups[$requirementId] = $this->group($pc, $obligation);
            }
            $groups[$requirementId]->rows[] = $this->rowOf($obligation, $bucket);
        }

        $row->groups = array_values($groups);
        $row->worstBucket = ($worst ?? ComplianceBucket::Ok)->value;

        return $row;
    }

    private function group(ProfileCompliance $pc, TrackedObligation $obligation): RequirementGroupDTO
    {
        $requirementId = $obligation->requirementId();
        $group = new RequirementGroupDTO();
        $group->requirementId = $requirementId;
        $group->name = $obligation->requirementName();
        $group->type = $obligation->type()->value;
        $group->typeLabel = $obligation->type()->title();
        $group->openDraftId = $pc->openDraftFor($requirementId)?->getId();
        foreach ($pc->signedDocumentsFor($requirementId) as $act) {
            $group->signedActs[] = new IssuanceActDTO($act->getId(), $act->signedAt()?->format('d.m.Y'));
        }

        return $group;
    }

    private function rowOf(TrackedObligation $obligation, ComplianceBucket $bucket): ObligationBucketRowDTO
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

    private function spec(TrackedObligation $obligation): string
    {
        $quantity = $obligation->quantity()?->label();
        $cadence = $obligation->cadence()->label();

        return null !== $quantity && '' !== $quantity ? $quantity.' · '.$cadence : $cadence;
    }

    private function initials(string $lastName, string $firstName): string
    {
        return mb_strtoupper(mb_substr($lastName, 0, 1).mb_substr($firstName, 0, 1));
    }
}
