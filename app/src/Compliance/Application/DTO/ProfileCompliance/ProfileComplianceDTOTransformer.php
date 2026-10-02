<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\ProfileCompliance;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Type\ComplianceStatus;

class ProfileComplianceDTOTransformer
{
    public function __construct(private readonly ComplianceStatusResolver $resolver)
    {
    }

    public function fromEntity(ProfileCompliance $profileCompliance, \DateTimeImmutable $now): ProfileComplianceDTO
    {
        $dto = new ProfileComplianceDTO();
        $dto->profileId = $profileCompliance->getProfileId();

        $worst = ComplianceStatus::Green;
        foreach ($profileCompliance->getObligations() as $obligation) {
            $row = $this->rowDto($obligation, $now);
            $dto->obligations[] = $row;
            $worst = ComplianceStatus::worseOf($worst, ComplianceStatus::from($row->status));
        }
        $dto->worstStatus = $worst->value;

        return $dto;
    }

    private function rowDto(TrackedObligation $obligation, \DateTimeImmutable $now): ObligationRowDTO
    {
        $row = new ObligationRowDTO();
        $row->key = $obligation->key();
        $row->requirementId = $obligation->requirementId();
        $row->requirementName = $obligation->requirementName();
        $row->label = $obligation->label();
        $row->type = $obligation->type()->value;
        $row->cadenceKind = $obligation->cadence()->kind->value;
        $row->cadenceNumber = $obligation->cadence()->number;
        $row->cadenceUnit = $obligation->cadence()->unit?->value;
        $row->cadenceLabel = $obligation->cadence()->label();
        $quantity = $obligation->quantity();
        $row->quantityLabel = $quantity?->label() ?? '';
        $row->quantityValue = null === $quantity ? null : (0.0 === fmod($quantity->amount, 1.0) ? (string) (int) $quantity->amount : (string) $quantity->amount);
        $row->quantityUnit = $quantity?->unit->value;
        $row->lastFulfilledAt = $obligation->lastFulfilledAt()?->format('Y-m-d');
        $row->nextDueAt = $obligation->nextDueAt()?->format('Y-m-d');
        $row->active = $obligation->isActive();
        $row->status = $this->resolver->statusFor(
            $obligation->isActive(),
            $obligation->lastFulfilledAt(),
            $obligation->nextDueAt(),
            $now,
            $obligation->quantity()?->amount,
            $obligation->heldQuantity(),
        )->value;

        return $row;
    }
}
